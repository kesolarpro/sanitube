<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use App\Models\User;
use RuntimeException;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Models\Release;

/**
 * Where one release could go, and where it has already gone.
 *
 * The screen behind the single irreversible act in the platform, so it is
 * built to make the two mistakes that cannot be taken back visible *before*
 * the button rather than after:
 *
 *   - **Submitting twice.** A delivery already exists for the pair, and its
 *     status is shown on the same row as the button. `UNIQUE(release_id,
 *     provider)` and a guarded status transition are what actually prevent it;
 *     this is so nobody has to find out that way.
 *   - **Submitting a real release to a sandbox.** A rehearsal is legitimate,
 *     which is exactly why it has to be labelled — a sandbox delivery that
 *     looks like a real one is discovered weeks later when stores have
 *     nothing.
 *
 * `none` is excluded from the list. It is the name of *not having* a
 * distributor, and offering it as a destination would be offering a
 * submission that cannot happen.
 */
final readonly class ReleaseDistributionQuery
{
    public function __construct(private DistributorManager $distributors) {}

    /**
     * @return array<string, mixed>
     */
    public function forRelease(Release $release, User $viewer): array
    {
        $mayDistribute = $viewer->role->canDistribute();

        $deliveries = DistributionDelivery::query()
            ->where('release_id', $release->getKey())
            ->get()
            ->keyBy('provider');

        $destinations = [];

        foreach ($this->distributors->names() as $name) {
            if ($name === DistributorManager::DISABLED) {
                continue;
            }

            $delivery = $deliveries->get($name);
            $state = $this->state($name);

            $destinations[] = [
                'provider' => $name,
                'known' => $state['known'],
                'available' => $state['available'],
                'sandbox' => $state['sandbox'],
                'delivery' => $delivery instanceof DistributionDelivery ? [
                    'uuid' => $delivery->uuid,
                    'status' => $delivery->status->value,
                    'submitted_at' => $delivery->submitted_at?->toAtomString(),
                ] : null,

                // Presentation. SubmitDelivery re-checks every one of these,
                // and a test posts past the hidden button to prove it.
                'can_submit' => $mayDistribute
                    && $state['available'] === true
                    && $release->status === ReleaseStatus::Ready
                    && (! $delivery instanceof DistributionDelivery || $delivery->status->isSubmittable()),

                'can_validate' => $mayDistribute && $state['available'] === true,
            ];
        }

        return [
            'release' => [
                'uuid' => $release->uuid,
                'title' => $release->title,
                'status' => $release->status->value,
            ],

            // Only a READY release may be handed over. Said here rather than
            // inferred from a disabled button, because "why can I not send
            // this" is the question this screen exists to answer.
            'release_is_ready' => $release->status === ReleaseStatus::Ready,

            'may_distribute' => $mayDistribute,
            'destinations' => $destinations,
        ];
    }

    /**
     * @return array{known: bool, available: bool, sandbox: bool|null}
     */
    private function state(string $name): array
    {
        try {
            $distributor = $this->distributors->distributor($name);
        } catch (RuntimeException) {
            // Configured but unresolvable. Not available, and not silently
            // dropped either — a destination that vanishes from the list looks
            // like it was never configured.
            return ['known' => false, 'available' => false, 'sandbox' => null];
        }

        return [
            'known' => true,
            'available' => $distributor->isAvailable(),
            'sandbox' => $distributor->isSandbox(),
        ];
    }
}
