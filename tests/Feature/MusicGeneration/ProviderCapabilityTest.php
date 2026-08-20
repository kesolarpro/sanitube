<?php

declare(strict_types=1);

namespace Tests\Feature\MusicGeneration;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\MusicGeneration\Contracts\Capabilities\SupportsExtend;
use SaniTube\MusicGeneration\Contracts\Capabilities\SupportsStems;
use SaniTube\MusicGeneration\Contracts\DeclaresCapabilities;
use SaniTube\MusicGeneration\Contracts\GeneratedAudioReader;
use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\Contracts\SynchronousMusicGenerationProvider;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\GenerationCapability;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\MusicGeneration\GenerationExecution;
use SaniTube\MusicGeneration\GenerationRequest;
use SaniTube\MusicGeneration\GenerationResult;
use SaniTube\MusicGeneration\GenerationStatus;
use SaniTube\MusicGeneration\Jobs\SubmitMusicGenerationJob;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\ProviderResult;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;
use SaniTube\MusicGeneration\Providers\UnavailableMusicGenerationProvider;
use SaniTube\MusicGeneration\Services\ProviderCapabilities;
use SaniTube\MusicGeneration\Services\SelectGenerationProvider;
use SaniTube\MusicGeneration\Services\StartMusicGeneration;
use SaniTube\MusicGeneration\Testing\InMemoryGeneratedAudioReader;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * GEN-006 — asking a supplier what it does, instead of assuming.
 *
 * The research this ticket follows found exactly one music provider whose
 * contract could be verified from this environment, and that contract turned
 * out to be **synchronous** — no job id, no polling, no webhook, no cancel —
 * while `MusicGenerationProvider` was written on the assumption that generation
 * is asynchronous. One verified provider was enough to show that assuming a
 * supplier's shape is how an adapter layer acquires a silent dependency on its
 * first supplier.
 *
 * Two claims carry the ticket, and both are negative:
 *
 *   1. **A provider is never handed a request it cannot serve.** The refusal
 *      arrives before a row exists and names the missing capability.
 *   2. **A fresh install does not quietly generate.** `generation.providers`
 *      ships with the fake in it, so "fall back to anything configured" would
 *      mean an installation whose default is `none` answering a request for
 *      music with synthetic audio and nobody being told.
 *
 * Everything else here is about evidence: what counts as knowing that a
 * provider can do something.
 */
final class ProviderCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $store);

        $this->app->instance(GeneratedAudioReader::class, new InMemoryGeneratedAudioReader);

        Queue::fake();
    }

    // ------------------------------------------------------ what counts as evidence

    #[Test]
    public function implementing_a_contract_proves_what_it_proves_and_nothing_else(): void
    {
        // A prompt goes in and music comes out: that is what every provider
        // contract has in common, so that is all the general list holds.
        $this->assertSame(
            [GenerationCapability::TextToMusic],
            GenerationCapability::structural(),
        );

        // GEN-007: implementing a *particular* sub-contract proves one more
        // thing -- the shape the supplier answers in. That is a method which
        // exists, not a claim, so it is read off the interface too.
        $this->assertSame(
            [GenerationCapability::TextToMusic, GenerationCapability::AsyncPolling],
            $this->capabilities()->for(new SilentProvider),
        );

        // And a synchronous supplier gets the other one, without ever having
        // implemented a method it has no answer for.
        $this->assertSame(
            [GenerationCapability::TextToMusic, GenerationCapability::Synchronous],
            $this->capabilities()->for(new SynchronousStubProvider),
        );
    }

    #[Test]
    public function a_method_that_exists_is_stronger_evidence_than_a_list(): void
    {
        // The provider below declares nothing beyond the default and mentions
        // neither EXTEND nor STEM_SEPARATION. It implements both interfaces,
        // and a provider cannot forget to mention a method it has.
        $found = $this->capabilities()->for(new AbleProvider);

        $this->assertContains(GenerationCapability::Extend, $found);
        $this->assertContains(GenerationCapability::StemSeparation, $found);
    }

    #[Test]
    public function cancellation_must_be_declared_and_is_never_inferred(): void
    {
        // `cancelGeneration()` is on the base contract and returning false for
        // ever is a documented answer, so having the method is not evidence.
        // A synchronous provider has nothing to cancel.
        $this->assertTrue(GenerationCapability::Cancel->mustBeDeclared());
        $this->assertFalse(GenerationCapability::TextToMusic->mustBeDeclared());

        $this->assertNotContains(
            GenerationCapability::Cancel,
            $this->capabilities()->for(new SilentProvider),
        );

        $this->assertContains(
            GenerationCapability::Cancel,
            $this->capabilities()->for(new FakeMusicGenerationProvider),
        );
    }

    #[Test]
    public function the_absence_of_a_provider_claims_nothing_at_all(): void
    {
        // Not an unconfigured supplier whose capabilities are unknown -- the
        // absence of one. Inheriting the structural TEXT_TO_MUSIC would put a
        // button on a fresh install that can only fail.
        $this->assertSame([], $this->capabilities()->for(new UnavailableMusicGenerationProvider));
    }

    #[Test]
    public function capabilities_come_back_in_one_order_however_they_were_found(): void
    {
        // Discovery order is an accident of which source answered first. A
        // stable order is what lets two providers be compared and an interface
        // list them without shuffling between requests.
        $found = $this->capabilities()->for(new AbleProvider);

        $sorted = $found;
        usort($sorted, static fn (GenerationCapability $a, GenerationCapability $b): int => array_search($a, GenerationCapability::cases(), true) <=> array_search($b, GenerationCapability::cases(), true));

        $this->assertSame($sorted, $found);
    }

    // ------------------------------------------------------ what an operator may change

    #[Test]
    public function an_operator_can_switch_off_something_their_deployment_lacks(): void
    {
        // The real case, and not a rare one: a self-hosted deployment with no
        // stem model installed cannot separate stems however capable the
        // software is, and only the operator knows that.
        $capabilities = new ProviderCapabilities([
            'able' => ['disabled_capabilities' => ['STEM_SEPARATION']],
        ]);

        $found = $capabilities->for(new AbleProvider);

        $this->assertNotContains(GenerationCapability::StemSeparation, $found);
        $this->assertContains(GenerationCapability::Extend, $found);
    }

    #[Test]
    public function configuration_can_take_a_capability_away_and_never_grant_one(): void
    {
        // A capability granted in a config file produces a failure at the
        // supplier that looks like a bug in SaniTube, and lets an installation
        // overrule the one place that has actually read the contract. There is
        // deliberately no key that does it.
        $capabilities = new ProviderCapabilities([
            'silent' => [
                'capabilities' => ['STEM_SEPARATION'],
                'enabled_capabilities' => ['STEM_SEPARATION'],
                'disabled_capabilities' => [],
            ],
        ]);

        $this->assertSame(
            [GenerationCapability::TextToMusic, GenerationCapability::AsyncPolling],
            $capabilities->for(new SilentProvider),
        );
    }

    #[Test]
    public function a_typo_in_the_configuration_is_ignored_rather_than_fatal(): void
    {
        // A misspelling must not stop generation from booting, and the
        // capability it failed to withhold is still checked by the provider.
        $capabilities = new ProviderCapabilities([
            'able' => ['disabled_capabilities' => ['stem_separation', 'NOT_A_CAPABILITY', 42]],
        ]);

        // Lower case is the same capability; the other two are not capabilities.
        $this->assertNotContains(GenerationCapability::StemSeparation, $capabilities->for(new AbleProvider));
        $this->assertContains(GenerationCapability::Extend, $capabilities->for(new AbleProvider));
    }

    // ------------------------------------------------------ choosing a supplier

    #[Test]
    public function a_request_names_what_it_needs_and_nothing_more(): void
    {
        $this->assertSame(
            [GenerationCapability::TextToMusic],
            StartMusicGeneration::requires(null, false),
        );

        $this->assertSame(
            [GenerationCapability::TextToMusic, GenerationCapability::LyricsToMusic],
            StartMusicGeneration::requires('one two three', false),
        );

        // An instrumental drops the lyrics, so it does not require a supplier
        // that can sing them -- {@see StartMusicGeneration::handle()} never
        // sends them.
        $this->assertSame(
            [GenerationCapability::TextToMusic, GenerationCapability::Instrumental],
            StartMusicGeneration::requires('one two three', true),
        );

        // Blank is not lyrics.
        $this->assertSame(
            [GenerationCapability::TextToMusic],
            StartMusicGeneration::requires("  \n ", false),
        );
    }

    #[Test]
    public function a_provider_is_never_handed_a_request_it_cannot_serve(): void
    {
        $this->useProvider((new FakeMusicGenerationProvider)->supporting([
            GenerationCapability::TextToMusic,
        ]));

        try {
            $this->starter()->handle(prompt: 'a ballad', lyrics: 'the words I wrote');
            $this->fail('A provider that cannot sing supplied lyrics must refuse them.');
        } catch (GenerationException $exception) {
            $this->assertSame('PROVIDER_INCAPABLE', $exception->reason);
            // Named, so the operator can act: switch supplier, or stop
            // supplying lyrics.
            $this->assertStringContainsString('LYRICS_TO_MUSIC', $exception->getMessage());
        }

        // Refused before a row exists. Nothing was created that could only
        // fail later.
        $this->assertSame(0, MusicGeneration::query()->count());
    }

    #[Test]
    public function the_same_supplier_serves_the_request_it_can(): void
    {
        $this->useProvider((new FakeMusicGenerationProvider)->supporting([
            GenerationCapability::TextToMusic,
        ]));

        $generation = $this->starter()->handle(prompt: 'a ballad');

        $this->assertSame('fake', $generation->provider);
        $this->assertSame(MusicGenerationStatus::Queued, $generation->status);
    }

    #[Test]
    public function a_fresh_installation_does_not_quietly_generate(): void
    {
        // `generation.providers` ships with the fake in it, because the fake is
        // what makes the Studio demonstrable without a supplier account.
        // Treating a configured entry as a fallback would mean an installation
        // whose default is `none` answering a request for music with synthetic
        // audio, and the operator would have no reason to suspect it.
        config(['generation.default' => MusicGenerationManager::DISABLED, 'generation.preference' => []]);
        $this->app->forgetInstance(MusicGenerationManager::class);

        $this->assertContains('fake', $this->app->make(MusicGenerationManager::class)->names());

        try {
            $this->starter()->handle(prompt: 'anything');
            $this->fail('A fresh installation must refuse rather than substitute a provider.');
        } catch (GenerationException $exception) {
            $this->assertSame('PROVIDER_UNAVAILABLE', $exception->reason);
        }

        $this->assertSame(0, MusicGeneration::query()->count());
    }

    #[Test]
    public function falling_back_to_a_second_supplier_is_something_an_operator_asks_for(): void
    {
        $this->useProvider((new FakeMusicGenerationProvider('primary', false)));
        $this->app->make(MusicGenerationManager::class)->register('secondary', new FakeMusicGenerationProvider('secondary'));

        config([
            'generation.default' => 'primary',
            'generation.providers.secondary' => ['driver' => 'fake'],
            'generation.preference' => ['primary', 'secondary'],
        ]);

        $generation = $this->starter()->handle(prompt: 'a ballad');

        // The first is unavailable and the second was named on purpose.
        $this->assertSame('secondary', $generation->provider);
    }

    #[Test]
    public function a_named_provider_is_refused_rather_than_replaced(): void
    {
        // An operator who asked for one supplier and got another would have no
        // way to know, and the row would record a decision nobody made.
        $this->useProvider(new FakeMusicGenerationProvider('primary', false));
        $this->app->make(MusicGenerationManager::class)->register('secondary', new FakeMusicGenerationProvider('secondary'));

        config([
            'generation.default' => 'primary',
            'generation.providers.secondary' => ['driver' => 'fake'],
            'generation.preference' => ['primary', 'secondary'],
        ]);

        try {
            $this->starter()->handle(prompt: 'a ballad', providerName: 'primary');
            $this->fail('A named unavailable provider must refuse.');
        } catch (GenerationException $exception) {
            $this->assertSame('PROVIDER_UNAVAILABLE', $exception->reason);
        }

        $this->assertSame(0, MusicGeneration::query()->count());
    }

    #[Test]
    public function a_failing_supplier_is_passed_over_before_it_is_refused(): void
    {
        // GEN-005 made the breaker refuse. With somewhere else to go, the right
        // answer is to go there: a provider having an outage should cost the
        // backlog a detour, not a stop.
        $this->useProvider(new FakeMusicGenerationProvider('primary'));
        $this->app->make(MusicGenerationManager::class)->register('secondary', new FakeMusicGenerationProvider('secondary'));

        config([
            'generation.default' => 'primary',
            'generation.providers.secondary' => ['driver' => 'fake'],
            'generation.preference' => ['primary', 'secondary'],
            'generation.circuit.consecutive_failures' => 2,
        ]);

        $this->recordFailures('primary', 2);

        $generation = $this->starter()->handle(prompt: 'a ballad');

        $this->assertSame('secondary', $generation->provider);
    }

    #[Test]
    public function a_failing_supplier_with_nowhere_to_go_still_refuses(): void
    {
        $this->useProvider(new FakeMusicGenerationProvider('primary'));

        config([
            'generation.default' => 'primary',
            'generation.preference' => [],
            'generation.circuit.consecutive_failures' => 2,
        ]);

        $this->recordFailures('primary', 2);

        try {
            $this->starter()->handle(prompt: 'a ballad');
            $this->fail('An open circuit with no alternative must refuse.');
        } catch (GenerationException $exception) {
            $this->assertSame('PROVIDER_CIRCUIT_OPEN', $exception->reason);
        }
    }

    #[Test]
    public function a_second_supplier_is_not_a_way_around_the_ceiling(): void
    {
        // Ceilings are a property of the installation, not of a supplier.
        // Moving a request to another provider to get past one would defeat it,
        // which is why selection deliberately does not consider it.
        $this->useProvider(new FakeMusicGenerationProvider('primary'));
        $this->app->make(MusicGenerationManager::class)->register('secondary', new FakeMusicGenerationProvider('secondary'));

        config([
            'generation.default' => 'primary',
            'generation.providers.secondary' => ['driver' => 'fake'],
            'generation.preference' => ['primary', 'secondary'],
            'generation.limits.daily' => 1,
        ]);

        MusicGeneration::query()->create([
            'provider' => 'primary',
            'status' => MusicGenerationStatus::Completed,
            'prompt' => 'the one that left',
            'language_code' => 'und',
            'commercial_rights_status' => CommercialRightsStatus::Unknown,
            'submitted_at' => now()->subHour(),
        ]);

        try {
            $this->starter()->handle(prompt: 'one more');
            $this->fail('A reached ceiling must refuse whatever else is configured.');
        } catch (GenerationException $exception) {
            $this->assertSame('CEILING_REACHED', $exception->reason);
        }
    }

    #[Test]
    public function the_refusal_reported_is_the_one_from_the_supplier_that_was_preferred(): void
    {
        // Two providers fall short for different reasons. Reporting the last
        // one examined would send the operator to fix whichever provider
        // happened to sort last, which is not the one they meant to use.
        $this->useProvider(new FakeMusicGenerationProvider('primary', false));
        $this->app->make(MusicGenerationManager::class)->register(
            'secondary',
            (new FakeMusicGenerationProvider('secondary'))->supporting([GenerationCapability::TextToMusic]),
        );

        config([
            'generation.default' => 'primary',
            'generation.providers.secondary' => ['driver' => 'fake'],
            'generation.preference' => ['primary', 'secondary'],
        ]);

        try {
            $this->starter()->handle(prompt: 'a ballad', lyrics: 'the words I wrote');
            $this->fail('Neither provider can serve this, so it must refuse.');
        } catch (GenerationException $exception) {
            // `primary` is unavailable; `secondary` cannot sing supplied
            // lyrics. The first is the preferred one and its problem is the
            // one worth reporting.
            $this->assertSame('PROVIDER_UNAVAILABLE', $exception->reason);
            $this->assertStringContainsString('primary', $exception->getMessage());
        }
    }

    #[Test]
    public function the_alternatives_to_a_provider_that_failed_can_be_asked_for(): void
    {
        // What makes a failed generation recoverable rather than lost: the
        // caller holding the work asks what else would do, naming what it has
        // already tried.
        $this->useProvider(new FakeMusicGenerationProvider('primary'));
        $this->app->make(MusicGenerationManager::class)->register('secondary', new FakeMusicGenerationProvider('secondary'));

        config([
            'generation.default' => 'primary',
            'generation.providers.secondary' => ['driver' => 'fake'],
            'generation.preference' => ['primary', 'secondary'],
        ]);

        $selection = $this->app->make(SelectGenerationProvider::class);

        $this->assertSame(['primary', 'secondary'], $selection->candidates());
        $this->assertSame(['secondary'], $selection->candidates(except: ['primary']));
        $this->assertSame([], $selection->candidates(except: ['primary', 'secondary']));
    }

    #[Test]
    public function nothing_left_to_try_says_so_in_its_own_words(): void
    {
        $this->useProvider(new FakeMusicGenerationProvider('primary'));
        config(['generation.default' => 'primary', 'generation.preference' => []]);

        try {
            $this->app->make(SelectGenerationProvider::class)->select(except: ['primary']);
            $this->fail('An exhausted candidate list must refuse.');
        } catch (GenerationException $exception) {
            // Distinct from PROVIDER_INCAPABLE: that one names a supplier that
            // fell short, this one says the search came back empty. Different
            // actions, so different codes.
            $this->assertSame('NO_CAPABLE_PROVIDER', $exception->reason);
        }
    }

    // ------------------------------------------------------ the production path

    #[Test]
    public function the_studio_form_is_what_selects_a_provider_in_production(): void
    {
        // WHO CALLS THIS IN PRODUCTION: POST /studio/generations ->
        // StudioActionController::startGeneration -> StartMusicGeneration ->
        // SelectGenerationProvider. No route, no selection.
        $this->useProvider((new FakeMusicGenerationProvider)->supporting([
            GenerationCapability::TextToMusic,
        ]));

        $this->actingAs($this->owner())
            ->post('/studio/generations', ['prompt' => 'A quiet ballad', 'lyrics' => 'the words I wrote'])
            ->assertSessionHasErrors(['generation' => 'PROVIDER_INCAPABLE']);

        $this->assertSame(0, MusicGeneration::query()->count());
        Queue::assertNothingPushed();

        // The same form, asking for something this supplier does.
        $this->actingAs($this->owner())
            ->post('/studio/generations', ['prompt' => 'A quiet ballad'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, MusicGeneration::query()->count());
        Queue::assertPushed(SubmitMusicGenerationJob::class);
    }

    #[Test]
    public function the_studio_screen_says_what_the_supplier_can_do(): void
    {
        // Without this, a request for stems from a provider that has none is
        // refused at submission with no warning anywhere on the screen that
        // produced it.
        $this->useProvider(new FakeMusicGenerationProvider);

        $this->actingAs($this->owner())
            ->get('/studio')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('studio.provider.available', true)
                ->where('studio.provider.capabilities', [
                    'TEXT_TO_MUSIC',
                    'LYRICS_TO_MUSIC',
                    'INSTRUMENTAL',
                    // GEN-007: read off the sub-contract the fake implements,
                    // not from its declaration -- which mentions neither.
                    'ASYNC_POLLING',
                    'CANCEL',
                ]));
    }

    #[Test]
    public function a_studio_with_no_provider_offers_no_capabilities_rather_than_omitting_the_key(): void
    {
        config(['generation.default' => MusicGenerationManager::DISABLED]);
        $this->app->forgetInstance(MusicGenerationManager::class);

        $this->actingAs($this->owner())
            ->get('/studio')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('studio.provider.configured', false)
                ->where('studio.provider.capabilities', []));
    }

    #[Test]
    public function every_capability_the_interface_can_render_has_a_name_in_every_language(): void
    {
        // A capability added in PHP and not translated renders as its own key,
        // which is how an interface starts showing SHOUTING_SNAKE_CASE to an
        // operator.
        foreach (['en', 'fr', 'es', 'de', 'it', 'pt'] as $locale) {
            foreach (GenerationCapability::values() as $capability) {
                $key = 'ui.studio.capability.'.$capability;

                $this->assertNotSame(
                    $key,
                    trans($key, [], $locale),
                    sprintf('[%s] has no name in [%s].', $capability, $locale),
                );
            }
        }
    }

    // ------------------------------------------------------ helpers

    private function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities;
    }

    private function starter(): StartMusicGeneration
    {
        // Resolved fresh each time: the selection service reads configuration
        // at construction, and these tests change it.
        $this->app->forgetInstance(SelectGenerationProvider::class);

        return $this->app->make(StartMusicGeneration::class);
    }

    private function useProvider(FakeMusicGenerationProvider $provider): void
    {
        config(['generation.default' => $provider->name()]);
        $this->app->forgetInstance(MusicGenerationManager::class);
        $this->app->make(MusicGenerationManager::class)->register($provider->name(), $provider);
    }

    private function recordFailures(string $provider, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            MusicGeneration::query()->create([
                'provider' => $provider,
                'status' => MusicGenerationStatus::Failed,
                'prompt' => 'failed '.$index,
                'language_code' => 'und',
                'commercial_rights_status' => CommercialRightsStatus::Unknown,
                'submitted_at' => now()->subMinutes($count - $index),
                'completed_at' => now()->subMinutes($count - $index),
            ]);
        }
    }

    private ?User $owner = null;

    private function owner(): User
    {
        if ($this->owner instanceof User) {
            return $this->owner;
        }

        $user = User::query()->create([
            'name' => 'Operator',
            'email' => 'owner@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        $user->forceFill(['role' => UserRole::Owner, 'is_active' => true])->save();

        return $this->owner = $user->refresh();
    }
}

/**
 * A provider that implements the contract and says nothing else.
 *
 * What most adapters will look like on the day they are written, and the case
 * that decides how much the platform may assume.
 */
final class SilentProvider implements MusicGenerationProvider
{
    public function name(): string
    {
        return 'silent';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function createGeneration(GenerationRequest $request): GenerationResult
    {
        return new GenerationResult('silent', 'job', GenerationStatus::Pending);
    }

    public function getGenerationStatus(string $providerJobId): GenerationResult
    {
        return new GenerationResult('silent', $providerJobId, GenerationStatus::Pending);
    }

    public function fetchResults(string $providerJobId): array
    {
        return [];
    }

    public function cancelGeneration(string $providerJobId): bool
    {
        return false;
    }
}

/**
 * A provider that answers with the audio.
 *
 * Named apart from GEN-007's own `AtOnceProvider` because test doubles share a
 * namespace and PHPUnit loads every file: two classes with one name is a fatal
 * error the moment both suites run together.
 *
 * The shape GEN-006's research actually found, and the reason GEN-007 exists:
 * it implements no `createGeneration`, no `getGenerationStatus`, no
 * `cancelGeneration`, and invents no job identifier for a job that never ran.
 */
final class SynchronousStubProvider implements SynchronousMusicGenerationProvider
{
    public function name(): string
    {
        return 'synchronous-stub';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function generate(GenerationRequest $request): GenerationExecution
    {
        return GenerationExecution::completed([
            new ProviderResult(providerResultId: 'one', sourceReference: 'memory://one'),
        ]);
    }
}

/**
 * A provider with two capability interfaces and a declaration that mentions
 * neither, so the two sources can be told apart.
 */
final class AbleProvider implements DeclaresCapabilities, MusicGenerationProvider, SupportsExtend, SupportsStems
{
    public function name(): string
    {
        return 'able';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function capabilities(): array
    {
        return [GenerationCapability::Webhook];
    }

    public function createGeneration(GenerationRequest $request): GenerationResult
    {
        return new GenerationResult('able', 'job', GenerationStatus::Pending);
    }

    public function getGenerationStatus(string $providerJobId): GenerationResult
    {
        return new GenerationResult('able', $providerJobId, GenerationStatus::Pending);
    }

    public function fetchResults(string $providerJobId): array
    {
        return [];
    }

    public function cancelGeneration(string $providerJobId): bool
    {
        return false;
    }

    public function extendGeneration(string $providerJobId, ?int $fromMs = null): GenerationResult
    {
        return new GenerationResult('able', $providerJobId, GenerationStatus::Pending);
    }

    public function fetchStems(string $providerJobId): array
    {
        return [];
    }
}
