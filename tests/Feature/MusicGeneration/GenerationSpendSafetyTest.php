<?php

declare(strict_types=1);

namespace Tests\Feature\MusicGeneration;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\Jobs\SubmitMusicGenerationJob;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;
use SaniTube\MusicGeneration\Services\GenerationCircuitBreaker;
use SaniTube\MusicGeneration\Services\GenerationSpendGuard;
use SaniTube\MusicGeneration\Services\PollMusicGeneration;
use SaniTube\MusicGeneration\Services\StartMusicGeneration;
use SaniTube\MusicGeneration\Services\SubmitMusicGeneration;
use SaniTube\MusicGeneration\Testing\InMemoryGeneratedAudioReader;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * GEN-004 — the two brakes on unattended generation.
 *
 * PROD-001 through PROD-003 built a planner whose entire purpose is to decide,
 * without a person present, that more music should exist. Nothing bounded how
 * many requests that could turn into. A completion is cents and already had a
 * ceiling (ENR-003); a generation is not, and had none.
 *
 * **This counts requests, and holds no money.** A test at the bottom of this
 * file reads the source of both services and asserts there is no currency, no
 * price and no balance anywhere in them — the constraint is that SaniTube never
 * becomes a thing that handles money, and a spend guard is exactly where that
 * would start.
 */
final class GenerationSpendSafetyTest extends TestCase
{
    use RefreshDatabase;

    private FakeMusicGenerationProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $store);

        $this->provider = new FakeMusicGenerationProvider;

        config(['generation.default' => 'fake']);
        $this->app->forgetInstance(MusicGenerationManager::class);
        $this->app->make(MusicGenerationManager::class)->register('fake', $this->provider);
        $this->app->instance(GeneratedAudioReader::class, new InMemoryGeneratedAudioReader);

        Queue::fake();
    }

    // --------------------------------------------------------- the ceiling

    #[Test]
    public function no_ceiling_is_configured_out_of_the_box(): void
    {
        // A platform whose first experience of the feature is a refusal would
        // be a worse platform. The ceiling is for an operator who has decided.
        $guard = $this->guard();

        foreach (['daily', 'weekly', 'monthly'] as $window) {
            $this->assertSame(0, $guard->ceiling($window));
            $this->assertNull($guard->remaining()[$window]);
        }

        $this->assertTrue($guard->allows());
        $this->assertNull($guard->exhaustedWindow());
    }

    #[Test]
    public function a_missing_setting_means_no_ceiling_rather_than_a_ceiling_of_one(): void
    {
        // Not hypothetical: an operator editing config/generation.php by hand,
        // or an older config file surviving an upgrade, leaves the key absent.
        // The fallback has to be the same "no ceiling" the shipped file states,
        // because any other default silently throttles an installation that
        // never asked to be throttled.
        config(['generation.limits' => []]);

        $this->assertSame(0, $this->guard()->ceiling('daily'));
        $this->assertTrue($this->guard()->allows());
    }

    #[Test]
    public function a_reached_ceiling_refuses_before_a_row_exists(): void
    {
        config(['generation.limits.daily' => 2]);

        // Submitted, not merely started. The ceiling counts requests that
        // reached a provider, and starting reaches nobody.
        $this->submitted('one');
        $this->submitted('two');

        try {
            $this->starter()->handle(prompt: 'three');
            $this->fail('A reached ceiling must refuse.');
        } catch (GenerationException $exception) {
            $this->assertSame('CEILING_REACHED', $exception->reason);
        }

        // Nothing created. A generation nobody can submit is worse than a
        // refusal: it sits QUEUED looking like work in progress.
        $this->assertSame(2, MusicGeneration::query()->count());
    }

    #[Test]
    public function only_requests_that_actually_left_this_server_count(): void
    {
        config(['generation.limits.daily' => 1]);

        // A generation refused before submission cost nothing, so it must not
        // consume the ceiling that produced it. Written directly, since the
        // service would refuse to make one.
        MusicGeneration::query()->create([
            'provider' => 'fake',
            'status' => MusicGenerationStatus::Failed,
            'prompt' => 'never sent',
            'language_code' => 'und',
            'instrumental' => false,
            'commercial_rights_status' => CommercialRightsStatus::Unknown,
            'submitted_at' => null,
        ]);

        $this->assertTrue($this->guard()->allows());
        $this->assertSame(1, $this->guard()->remaining()['daily']);

        // ...whereas one that did leave counts, whatever became of it.
        $this->submitted('sent');

        $this->assertFalse($this->guard()->allows());
    }

    #[Test]
    public function a_null_submission_time_is_excluded_by_the_comparison_itself(): void
    {
        // Why the `whereNotNull('submitted_at')` beside the window comparison
        // survives mutation: it is redundant. Three-valued logic makes
        // `NULL >= x` evaluate to NULL, which WHERE treats as not-true, so the
        // comparison alone already drops unsubmitted rows.
        //
        // Asserted rather than assumed. This runs on SQLite, MySQL 8 and both
        // MariaDBs in CI, which is the only way to know it holds on all four
        // rather than on the one a developer happened to use. The clause stays
        // because it states the intent at the point of the query; this test is
        // what makes keeping it honest instead of decorative.
        MusicGeneration::query()->create([
            'provider' => 'fake',
            'status' => MusicGenerationStatus::Queued,
            'prompt' => 'never sent',
            'language_code' => 'und',
            'instrumental' => false,
            'commercial_rights_status' => CommercialRightsStatus::Unknown,
            'submitted_at' => null,
        ]);

        $matched = MusicGeneration::query()
            ->where('submitted_at', '>=', Carbon::now()->subHours(24))
            ->count();

        $this->assertSame(0, $matched);
    }

    #[Test]
    public function the_window_rolls_rather_than_resetting_on_a_calendar(): void
    {
        config(['generation.limits.daily' => 1]);

        $generation = $this->submitted('yesterday');

        // Full first, or the assertion below would pass on an empty table and
        // prove nothing at all.
        $this->assertFalse($this->guard()->allows());

        $generation->forceFill(['submitted_at' => Carbon::now()->subHours(25)])->save();

        // Out of the 24-hour window, so there is room again -- with no reset
        // anybody had to remember and no time zone to get wrong.
        $this->assertTrue($this->guard()->allows());
    }

    #[Test]
    public function the_longest_exhausted_window_is_the_one_reported(): void
    {
        // Both are true; the monthly one is the one still true tomorrow, and
        // therefore the useful instruction.
        config(['generation.limits.daily' => 1, 'generation.limits.monthly' => 1]);

        $this->submitted('one');

        $this->assertSame('monthly', $this->guard()->exhaustedWindow());
    }

    #[Test]
    public function a_ceiling_reached_while_the_job_waited_stops_the_request(): void
    {
        // WHO CALLS THIS IN PRODUCTION: SubmitMusicGenerationJob. The person
        // clicked when there was room; the queue ran when there was not, with
        // a thousand siblings ahead of it. The check at the moment of spend is
        // the one that counts.
        $generation = $this->starter()->handle(prompt: 'ambient piano');

        config(['generation.limits.daily' => 1]);
        $this->consumeCeiling(1);

        (new SubmitMusicGenerationJob($generation->uuid))->handle($this->submitter());

        $stored = MusicGeneration::query()->findOrFail($generation->id);

        $this->assertSame(0, $this->provider->generationsCreated());
        $this->assertNull($stored->provider_job_id);
        $this->assertSame(MusicGenerationStatus::Failed, $stored->status);
        $this->assertStringContainsString('ceiling', (string) $stored->failure_reason);
    }

    // --------------------------------------------------------- the breaker

    #[Test]
    public function a_provider_that_keeps_failing_stops_being_called(): void
    {
        config(['generation.circuit.consecutive_failures' => 3]);

        $this->failSubmissions(3);

        $this->assertTrue($this->breaker()->isOpenFor('fake'));

        try {
            $this->starter()->handle(prompt: 'one more');
            $this->fail('An open circuit must refuse.');
        } catch (GenerationException $exception) {
            $this->assertSame('PROVIDER_CIRCUIT_OPEN', $exception->reason);
        }
    }

    #[Test]
    public function one_success_among_the_recent_failures_keeps_the_circuit_closed(): void
    {
        config(['generation.circuit.consecutive_failures' => 3]);

        $this->failSubmissions(2);
        $this->submitted('this one worked');

        $this->assertFalse($this->breaker()->isOpenFor('fake'));
    }

    #[Test]
    public function too_few_submissions_cannot_open_the_circuit(): void
    {
        config(['generation.circuit.consecutive_failures' => 3]);

        $this->failSubmissions(2);

        $this->assertFalse($this->breaker()->isOpenFor('fake'));
    }

    #[Test]
    public function the_circuit_closes_itself_once_the_cooldown_passes(): void
    {
        // Half-open for free: the next request goes through, and if it fails it
        // becomes the newest failure and the window restarts. No probe state.
        config(['generation.circuit.consecutive_failures' => 2, 'generation.circuit.cooldown_minutes' => 15]);

        $this->failSubmissions(2);
        $this->assertTrue($this->breaker()->isOpenFor('fake'));

        MusicGeneration::query()->update(['submitted_at' => Carbon::now()->subMinutes(16)]);

        // A run of failures from last week is history, not an outage.
        $this->assertFalse($this->breaker()->isOpenFor('fake'));
    }

    #[Test]
    public function one_providers_outage_does_not_silence_another(): void
    {
        config(['generation.circuit.consecutive_failures' => 2]);

        $this->failSubmissions(2);

        $this->assertTrue($this->breaker()->isOpenFor('fake'));
        $this->assertFalse($this->breaker()->isOpenFor('some-other-provider'));
    }

    #[Test]
    public function a_zero_threshold_disables_the_breaker(): void
    {
        config(['generation.circuit.consecutive_failures' => 0]);

        $this->failSubmissions(5);

        $this->assertFalse($this->breaker()->isOpenFor('fake'));
    }

    #[Test]
    public function failures_before_submission_say_nothing_about_the_provider(): void
    {
        config(['generation.circuit.consecutive_failures' => 2]);

        // A run of "no provider configured" rows is a statement about this
        // installation, not about the vendor. Counting them would open the
        // circuit on an installation that has never made a single request.
        for ($i = 0; $i < 5; $i++) {
            MusicGeneration::query()->create([
                'provider' => 'fake',
                'status' => MusicGenerationStatus::Failed,
                'prompt' => 'never sent '.$i,
                'language_code' => 'und',
                'instrumental' => false,
                'commercial_rights_status' => CommercialRightsStatus::Unknown,
                'submitted_at' => null,
            ]);
        }

        $this->assertFalse($this->breaker()->isOpenFor('fake'));
    }

    #[Test]
    public function an_unsubmitted_row_between_two_failures_does_not_make_them_three(): void
    {
        // The discriminating case, and it took a surviving mutation to find it.
        // "failures_before_submission_say_nothing_about_the_provider" passes
        // whether or not the query filters unsubmitted rows, because such a row
        // has no submitted_at and therefore also fails the freshness check --
        // the right answer for the wrong reason, which is no proof at all.
        //
        // Here the unsubmitted row is in the *middle*. Two genuine failures
        // must not open a threshold-of-three circuit merely because a row that
        // never reached the provider sits between them.
        config(['generation.circuit.consecutive_failures' => 3]);

        $this->failSubmissions(1);

        MusicGeneration::query()->create([
            'provider' => 'fake',
            'status' => MusicGenerationStatus::Failed,
            'prompt' => 'never sent',
            'language_code' => 'und',
            'instrumental' => false,
            'commercial_rights_status' => CommercialRightsStatus::Unknown,
            'submitted_at' => null,
        ]);

        $this->failSubmissions(1);

        $this->assertSame(3, MusicGeneration::query()->count());
        $this->assertSame(2, MusicGeneration::query()->whereNotNull('submitted_at')->count());
        $this->assertFalse($this->breaker()->isOpenFor('fake'));
    }

    #[Test]
    public function a_circuit_that_opened_while_the_job_waited_stops_the_request(): void
    {
        // WHO CALLS THIS IN PRODUCTION: SubmitMusicGenerationJob. The mirror of
        // the ceiling case, and the mutation that found it missing had removed
        // this very check from the submit path with every circuit test still
        // green -- because all of them went through StartMusicGeneration.
        config(['generation.circuit.consecutive_failures' => 2]);

        // Started while the provider was healthy...
        $generation = $this->starter()->handle(prompt: 'ambient piano');
        $before = $this->provider->generationsCreated();

        // ...and the provider fell over before the queue got to it.
        $this->failSubmissions(2);
        $this->assertTrue($this->breaker()->isOpenFor('fake'));

        $after = $this->provider->generationsCreated();
        (new SubmitMusicGenerationJob($generation->uuid))->handle($this->submitter());

        // Not asked. The whole point is that a backlog does not discover an
        // outage once per item.
        $this->assertSame($after, $this->provider->generationsCreated());
        $this->assertGreaterThan($before, $after);

        $stored = MusicGeneration::query()->findOrFail($generation->id);
        $this->assertNull($stored->provider_job_id);
        $this->assertSame(MusicGenerationStatus::Failed, $stored->status);
        $this->assertStringContainsString('failed repeatedly', (string) $stored->failure_reason);
    }

    // ------------------------------------------------------- and no money

    #[Test]
    public function nothing_here_holds_money(): void
    {
        // The load-bearing negative claim. A spend guard is exactly where a
        // platform starts handling money, and this one must not.
        $forbidden = ['price', 'cost', 'currency', 'balance', 'usd', 'eur', 'invoice', 'billing', 'payout', 'royalt'];

        foreach ([
            'src/MusicGeneration/Services/GenerationSpendGuard.php',
            'src/MusicGeneration/Services/GenerationCircuitBreaker.php',
        ] as $file) {
            $source = (string) file_get_contents(base_path($file));

            // Comments stripped and code alone scanned: the docblocks *promise*
            // there is no currency here, and an earlier version of this test
            // failed on its own explanation of itself.
            $code = '';

            foreach (token_get_all($source) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= is_array($token) ? $token[1] : $token;
            }

            foreach ($forbidden as $word) {
                $this->assertStringNotContainsString($word, strtolower($code), $file.' must not mention '.$word);
            }

            foreach (['$', '€', '£'] as $symbol) {
                $this->assertStringNotContainsString($symbol.'0', $code);
            }
        }
    }

    // ----------------------------------------------------------- fixtures

    /**
     * Submit and then fail `$count` generations, as a provider outage would.
     */
    private function failSubmissions(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $generation = $this->submitter()->handle($this->starter()->handle(prompt: 'attempt '.$i));

            $this->provider->fail((string) $generation->provider_job_id, 'Upstream error.');
            $this->app->make(PollMusicGeneration::class)->handle($generation);
        }
    }

    private function consumeCeiling(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            MusicGeneration::query()->create([
                'provider' => 'fake',
                'status' => MusicGenerationStatus::Processing,
                'prompt' => 'already sent '.$i,
                'language_code' => 'und',
                'instrumental' => false,
                'commercial_rights_status' => CommercialRightsStatus::Unknown,
                'submitted_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * Started *and* submitted.
     *
     * The distinction is the whole semantic: `submitted_at` is written when a
     * request actually left this server, and that is what both brakes read.
     */
    private function submitted(string $prompt): MusicGeneration
    {
        return $this->submitter()->handle($this->starter()->handle(prompt: $prompt));
    }

    private function guard(): GenerationSpendGuard
    {
        return $this->app->make(GenerationSpendGuard::class);
    }

    private function breaker(): GenerationCircuitBreaker
    {
        return $this->app->make(GenerationCircuitBreaker::class);
    }

    private function starter(): StartMusicGeneration
    {
        return $this->app->make(StartMusicGeneration::class);
    }

    private function submitter(): SubmitMusicGeneration
    {
        return $this->app->make(SubmitMusicGeneration::class);
    }
}
