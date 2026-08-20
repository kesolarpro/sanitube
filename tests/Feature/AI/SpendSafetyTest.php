<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\AI\AiCompletion;
use SaniTube\AI\AiManager;
use SaniTube\AI\AiPrompt;
use SaniTube\AI\Models\AiInvocation;
use SaniTube\AI\Services\AiCircuitBreaker;
use SaniTube\AI\Services\AiSpendGuard;
use SaniTube\AI\Services\RunPrompt;
use SaniTube\AI\Testing\FakeAiProvider;
use SaniTube\Enrichment\Jobs\SuggestMetadataJob;
use SaniTube\Transcription\Jobs\TranscribeAssetJob;
use Tests\TestCase;

/**
 * ENR-003 — how much this installation is willing to spend, and when it stops.
 *
 * **Operational safety, and deliberately not finance.** Everything here counts
 * *calls*, because calls are what the platform can observe. There is no price,
 * no currency, no balance and no invoice anywhere in it, and it is not a step
 * towards any of those: the question is "should we keep going", which is an
 * operations question.
 *
 * The failure it prevents is one this platform's design has walked up to twice.
 * A backfill over four thousand masters enqueues four thousand jobs. OPS-002's
 * ceiling bounds how many of those sit on a queue at once — and bounds nothing
 * whatsoever about how many reach a vendor over the following week. A queue
 * that drains steadily spends steadily, and the first sign of it is a bill.
 */
final class SpendSafetyTest extends TestCase
{
    use RefreshDatabase;

    private FakeAiProvider $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = new FakeAiProvider;
    }

    // ------------------------------------------------------------ the default

    #[Test]
    public function a_fresh_install_has_no_ceiling_and_says_so(): void
    {
        // A platform whose first experience of an AI feature is a refusal is a
        // platform nobody turns the feature on twice. The ceilings are for an
        // operator who has decided what they are willing to spend.
        $values = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

        foreach (['DAILY', 'WEEKLY', 'MONTHLY'] as $window) {
            $this->assertSame('0', $values['SANITUBE_AI_'.$window.'_CALLS'] ?? null);
        }

        $guard = $this->app->make(AiSpendGuard::class);

        $this->assertTrue($guard->allows());
        $this->assertNull($guard->exhaustedWindow());
        $this->assertSame(['daily' => null, 'weekly' => null, 'monthly' => null], $guard->remaining());
    }

    // ------------------------------------------------------------ the ceiling

    #[Test]
    public function the_daily_ceiling_stops_calls_once_it_is_reached(): void
    {
        config(['ai.limits.daily' => 2]);

        $this->assertTrue($this->ask()->isUsable());
        $this->assertTrue($this->ask()->isUsable());

        $refused = $this->ask();

        $this->assertFalse($refused->isUsable());
        $this->assertFalse($refused->attempted);
        $this->assertStringContainsString('daily', (string) $refused->failureMessage);

        // The provider was asked twice and no more.
        $this->assertCount(2, $this->model->prompts);
    }

    #[Test]
    public function a_refusal_does_not_count_towards_the_ceiling_that_produced_it(): void
    {
        // **The bug this shape exists to avoid.** A quota that counted its own
        // refusals would latch shut and never reopen, because every attempt to
        // check would push the count further past the line.
        config(['ai.limits.daily' => 1]);

        $this->ask();
        $this->ask();
        $this->ask();

        $this->assertSame(3, AiInvocation::query()->count(), 'Every call is in the ledger, including the ones that never happened.');
        $this->assertSame(1, AiInvocation::query()->where('attempted', true)->count());
        $this->assertSame(['daily' => 0, 'weekly' => null, 'monthly' => null], $this->app->make(AiSpendGuard::class)->remaining());
    }

    #[Test]
    public function a_call_that_never_left_this_server_is_free(): void
    {
        // An installation with no key spends nothing, and a hundred refusals
        // must not consume a ceiling.
        config(['ai.limits.daily' => 2]);
        $this->model->unavailable();

        $this->ask();
        $this->ask();
        $this->ask();

        $this->assertSame(0, AiInvocation::query()->where('attempted', true)->count());
        $this->assertTrue($this->app->make(AiSpendGuard::class)->allows());
    }

    #[Test]
    public function a_vendor_failure_counts_because_it_cost_a_request(): void
    {
        // The other direction, and the one a naive implementation gets wrong.
        // Counting by `succeeded` would let an installation burn a whole
        // ceiling's worth of 500s for free.
        config(['ai.limits.daily' => 2]);
        $this->model->willReturn(AiCompletion::failed('fake', 'the provider answered 500'));

        $this->ask();
        $this->ask();

        $this->assertSame(2, AiInvocation::query()->where('attempted', true)->count());
        $this->assertFalse($this->app->make(AiSpendGuard::class)->allows());
    }

    #[Test]
    public function the_window_is_rolling_rather_than_a_calendar_one(): void
    {
        // No time zone to get wrong, no reset job to forget, and no gaming it
        // by starting a sweep at 23:55.
        config(['ai.limits.daily' => 1]);

        $this->ask();
        $this->assertFalse($this->app->make(AiSpendGuard::class)->allows());

        AiInvocation::query()->update(['created_at' => Carbon::now()->subHours(25)]);

        $this->assertTrue($this->app->make(AiSpendGuard::class)->allows());
    }

    #[Test]
    public function the_longest_exhausted_window_is_the_one_reported(): void
    {
        // "You have run out" and "you have run out until the 3rd" are
        // different instructions, and the longer one is the one still true
        // tomorrow.
        config(['ai.limits.daily' => 1, 'ai.limits.monthly' => 1]);

        $this->ask();

        $this->assertSame('monthly', $this->app->make(AiSpendGuard::class)->exhaustedWindow());
    }

    #[Test]
    public function a_longer_window_still_holds_when_the_shorter_one_has_reset(): void
    {
        config(['ai.limits.daily' => 5, 'ai.limits.weekly' => 2]);

        $this->ask();
        $this->ask();

        AiInvocation::query()->update(['created_at' => Carbon::now()->subHours(30)]);

        $guard = $this->app->make(AiSpendGuard::class);

        $this->assertSame(5, $guard->remaining()['daily']);
        $this->assertSame('weekly', $guard->exhaustedWindow());
    }

    // ------------------------------------------------------- the breaker

    #[Test]
    public function a_provider_that_keeps_failing_stops_being_asked(): void
    {
        // A vendor outage answers every request identically and immediately,
        // and a worker holding two thousand jobs will discover that two
        // thousand times in the minutes before anybody notices.
        config(['ai.circuit.consecutive_failures' => 3]);
        $this->model->willReturn(AiCompletion::failed('fake', 'the provider answered 500'));

        $this->ask();
        $this->ask();
        $this->ask();

        $this->assertCount(3, $this->model->prompts);

        $refused = $this->ask();

        $this->assertFalse($refused->attempted);
        $this->assertCount(3, $this->model->prompts, 'The fourth call must not reach the provider.');
    }

    #[Test]
    public function one_success_among_the_failures_keeps_the_circuit_closed(): void
    {
        config(['ai.circuit.consecutive_failures' => 3]);

        $this->model->willReturn(AiCompletion::failed('fake', 'a 500'));
        $this->ask();
        $this->ask();

        $this->model->willReturn(new AiCompletion(provider: 'fake', text: 'an answer', model: 'fake-1'));
        $this->ask();

        $this->model->willReturn(AiCompletion::failed('fake', 'a 500'));
        $this->ask();
        $this->ask();

        // Two failures since the success is not three in a row.
        $this->assertTrue($this->ask()->attempted);
    }

    #[Test]
    public function the_circuit_closes_itself_once_the_cooldown_passes(): void
    {
        // Half-open for free: the first call after the cooldown is allowed
        // through, and if it fails the window simply starts again. No separate
        // probe state to get wrong.
        config(['ai.circuit.consecutive_failures' => 2, 'ai.circuit.cooldown_minutes' => 15]);
        $this->model->willReturn(AiCompletion::failed('fake', 'a 500'));

        $this->ask();
        $this->ask();
        $this->assertFalse($this->ask()->attempted);

        AiInvocation::query()->update(['created_at' => Carbon::now()->subMinutes(16)]);

        $this->assertTrue($this->ask()->attempted);
    }

    #[Test]
    public function a_run_of_failures_from_last_week_is_history_rather_than_an_outage(): void
    {
        // Without the cooldown check the breaker would latch permanently the
        // first time a provider had a bad afternoon and was then left unused.
        config(['ai.circuit.consecutive_failures' => 2, 'ai.circuit.cooldown_minutes' => 15]);
        $this->model->willReturn(AiCompletion::failed('fake', 'a 500'));

        $this->ask();
        $this->ask();

        AiInvocation::query()->update(['created_at' => Carbon::now()->subWeek()]);

        $this->assertFalse($this->app->make(AiCircuitBreaker::class)->isOpenFor('fake'));
    }

    #[Test]
    public function an_outage_at_one_vendor_does_not_stop_the_other(): void
    {
        config(['ai.circuit.consecutive_failures' => 2]);

        AiInvocation::query()->create([
            'provider' => 'openai', 'purpose' => 'x', 'prompt_version' => 'v1',
            'succeeded' => false, 'attempted' => true,
        ]);
        AiInvocation::query()->create([
            'provider' => 'openai', 'purpose' => 'x', 'prompt_version' => 'v1',
            'succeeded' => false, 'attempted' => true,
        ]);

        $breaker = $this->app->make(AiCircuitBreaker::class);

        $this->assertTrue($breaker->isOpenFor('openai'));
        $this->assertFalse($breaker->isOpenFor('claude'));
    }

    #[Test]
    public function calls_that_never_left_say_nothing_about_the_vendor(): void
    {
        // A run of "no key configured" rows is a statement about this
        // installation, not about whether the vendor is answering.
        config(['ai.circuit.consecutive_failures' => 2]);
        $this->model->unavailable();

        $this->ask();
        $this->ask();
        $this->ask();

        $this->assertFalse($this->app->make(AiCircuitBreaker::class)->isOpenFor('fake'));
    }

    #[Test]
    public function a_breaker_set_to_zero_is_off(): void
    {
        config(['ai.circuit.consecutive_failures' => 0]);
        $this->model->willReturn(AiCompletion::failed('fake', 'a 500'));

        for ($i = 0; $i < 6; $i++) {
            $this->ask();
        }

        $this->assertCount(6, $this->model->prompts);
    }

    // --------------------------------------------------- the gates are one place

    #[Test]
    public function the_ceiling_is_asked_before_the_breaker(): void
    {
        // The operator's own decision outranks a measurement, and "you have
        // reached your ceiling" is the more actionable of the two.
        config(['ai.limits.daily' => 1, 'ai.circuit.consecutive_failures' => 1]);
        $this->model->willReturn(AiCompletion::failed('fake', 'a 500'));

        $this->ask();

        $refused = $this->ask();

        $this->assertStringContainsString('ceiling', (string) $refused->failureMessage);
    }

    #[Test]
    public function a_refusal_is_a_completion_rather_than_an_exception(): void
    {
        // AI assists a person who could do the job without it. A feature that
        // threw when a ceiling was reached would break the workflow it exists
        // to help.
        config(['ai.limits.daily' => 0]);
        config(['ai.limits.monthly' => 1]);

        $this->ask();

        $refused = $this->ask();

        $this->assertInstanceOf(AiCompletion::class, $refused);
        $this->assertFalse($refused->isUsable());
        $this->assertNotNull($refused->failureMessage);
    }

    // ----------------------------------------------------------- max retry

    #[Test]
    public function the_jobs_that_spend_money_get_exactly_one_attempt(): void
    {
        // A retried job is a second billed call, and deciding whether to pay
        // twice is a person's decision rather than a queue worker's. Stated on
        // the classes rather than left to a framework default somebody can
        // change without ever seeing them.
        $this->assertSame(1, (new TranscribeAssetJob('a-uuid'))->tries);
        $this->assertSame(1, (new SuggestMetadataJob('a-uuid'))->tries);
    }

    // ------------------------------------------------------------ no finance

    #[Test]
    public function nothing_here_holds_money(): void
    {
        // SaniTube's scope excludes finance outright, and a spend guard is
        // exactly where that boundary erodes: one currency column, then a
        // rate, then a balance. This asserts the boundary rather than trusting
        // it to a reviewer's memory.
        $forbidden = ['currency', 'price', 'cost_cents', 'balance', 'invoice', 'billing_amount', 'exchange_rate'];

        foreach ([
            base_path('src/AI/Services/AiSpendGuard.php'),
            base_path('src/AI/Services/AiCircuitBreaker.php'),
        ] as $file) {
            $code = $this->codeWithoutComments($file);

            foreach ($forbidden as $word) {
                $this->assertStringNotContainsString(
                    $word,
                    $code,
                    sprintf('%s must not know about %s: this is operational safety, not accounting.', basename($file), $word),
                );
            }
        }
    }

    /**
     * The file with its comments removed.
     *
     * Scanned as code rather than as text, because both of these classes'
     * docblocks say at length that they hold no currency and no balance — and
     * a check that read the prose would fail on the sentence promising the
     * thing it is checking for. Which it did, the first time it ran.
     */
    private function codeWithoutComments(string $file): string
    {
        $kept = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $kept .= is_array($token) ? $token[1] : $token;
        }

        return mb_strtolower($kept);
    }

    // ------------------------------------------------------------ fixtures

    private function ask(): AiCompletion
    {
        $manager = new AiManager([], 'fake');
        $manager->register('fake', $this->model);

        return (new RunPrompt(
            $manager,
            $this->app->make(AiSpendGuard::class),
            $this->app->make(AiCircuitBreaker::class),
        ))->handle('suggest_title', new AiPrompt('Describe this.'), 'fake');
    }
}
