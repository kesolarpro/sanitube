<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use SaniTube\MusicGeneration\Enums\CommercialRightsStatus;
use SaniTube\MusicGeneration\Enums\GenerationCapability;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Exceptions\UnknownGenerationProvider;
use SaniTube\MusicGeneration\MusicGenerationManager;
use SaniTube\MusicGeneration\Services\ProviderCapabilities;

/**
 * What the studio can do, and what it has done.
 *
 * The provider state is the reason this screen exists. GEN-001 ships one real
 * capability — a fake provider — and no credentials for anything else, so the
 * honest thing for the studio to say on most installs is **"not configured"**.
 * A screen that offered a generate button regardless would produce a failure
 * the operator could only diagnose by reading logs.
 *
 * Three provider states, and they are genuinely different:
 *
 *   - **not configured** — `none`, the disabled provider. Nothing is wrong.
 *   - **configured but unavailable** — a provider is named and says it cannot
 *     run: missing credentials, unaccepted terms. Something is wrong and it is
 *     fixable.
 *   - **available** — it will accept work.
 *
 * `isAvailable()` is asked of the provider object, not of the network. No
 * outbound request happens on a page load; that rule was settled by UI-002's
 * review and applies to every provider surface.
 *
 * GEN-006 adds a fourth thing the screen has to be able to say: **what this
 * supplier can do**. Capability is not availability — a provider with an
 * expired key is still capable of everything it ever was — so the two are
 * reported side by side rather than folded together. Without this, a request
 * for stems from a provider that has none is refused at submission with no
 * warning anywhere on the screen that produced it.
 */
final readonly class StudioOverviewQuery
{
    public function __construct(
        private MusicGenerationManager $providers,
        private ProviderCapabilities $capabilities,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(?User $viewer = null): array
    {
        $provider = $this->provider();

        return [
            'provider' => $provider,

            // Both halves have to be true, and they fail for different
            // reasons: a MEMBER is not allowed to spend money at a supplier,
            // and nobody can when there is no supplier configured. The screen
            // says which.
            'may_generate' => ($viewer?->role->canWriteCatalogue() ?? false)
                && ($provider['available'] ?? false) === true,
            'generations' => $this->generationCounts(),
            'rights' => $this->rightsCounts(),
            'projects' => (int) DB::table('generation_projects')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function provider(): array
    {
        $name = $this->providers->defaultName();

        if ($name === MusicGenerationManager::DISABLED) {
            return ['name' => null, 'configured' => false, 'available' => false, 'capabilities' => []];
        }

        try {
            $provider = $this->providers->default();
        } catch (UnknownGenerationProvider) {
            // Named in configuration and not resolvable. Configured, plainly
            // unavailable, and worth showing rather than swallowing — an
            // operator who typed a provider name wrong needs to see that they
            // did, not an empty studio.
            return ['name' => $name, 'configured' => true, 'available' => false, 'capabilities' => []];
        }

        return [
            'name' => $provider->name(),
            'configured' => true,
            'available' => $provider->isAvailable(),
            // Names, not objects, and no endpoint, model or key among them.
            // What a supplier can do is not a secret; how to reach it is.
            'capabilities' => array_map(
                static fn (GenerationCapability $capability): string => $capability->value,
                $this->capabilities->for($provider),
            ),
        ];
    }

    /**
     * Generations per state, every state present.
     *
     * @return array<string, int>
     */
    private function generationCounts(): array
    {
        /** @var array<string, int> $counted */
        $counted = DB::table('music_generations')
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $totals = ['total' => 0];

        foreach (MusicGenerationStatus::cases() as $case) {
            $count = (int) ($counted[$case->value] ?? 0);

            $totals[$case->value] = $count;
            $totals['total'] += $count;
        }

        return $totals;
    }

    /**
     * Generations per commercial-rights state.
     *
     * Surfaced on the overview rather than buried on a detail page, because
     * UNKNOWN is the default and stays the default: nothing in SaniTube ever
     * infers a rights answer from a successful generation. An operator with
     * forty generated tracks and forty UNKNOWNs is looking at forty recordings
     * that may not be sold, and that is worth a number on the front page.
     *
     * @return array<string, int>
     */
    private function rightsCounts(): array
    {
        /** @var array<string, int> $counted */
        $counted = DB::table('music_generations')
            ->selectRaw('commercial_rights_status, count(*) as aggregate')
            ->groupBy('commercial_rights_status')
            ->pluck('aggregate', 'commercial_rights_status')
            ->all();

        $totals = [];

        foreach (CommercialRightsStatus::cases() as $case) {
            $totals[$case->value] = (int) ($counted[$case->value] ?? 0);
        }

        return $totals;
    }
}
