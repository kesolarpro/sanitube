<?php

declare(strict_types=1);

namespace SaniTube\Production\Services;

use Illuminate\Database\Eloquent\Builder;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;
use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\MusicGeneration\Enums\MusicGenerationStatus;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Production\Models\ProductionSlot;
use SaniTube\Production\ProductionInventory;

/**
 * Counting what a plan already has, before anything is made for it.
 *
 * **Do not generate blindly.** Autonomous generation without this is a machine
 * that produces forty tracks because forty is the number in the plan,
 * regardless of the thirty-eight already sitting unreleased — and every one of
 * those forty is a paid call to somebody else's server.
 *
 * **In-flight is counted, and that is the half everyone forgets.** Work asked
 * for and not yet finished has nothing to show for it, so a count that looked
 * only at what exists would order more every time it ran — turning a plan that
 * wants forty tracks into one that has ordered four hundred, all correctly, one
 * slot at a time.
 *
 * ## What counts as this plan's
 *
 * Through the plan's editorial profile to that profile's default artist, and
 * from there to the tracks credited to it as primary. That is the only
 * attribution the platform currently has, and it is a real one: a plan produces
 * for an imprint, and an imprint releases under a name.
 *
 * **A profile with no default artist can hold no stock**, and the inventory
 * says so rather than reporting zero. The two look identical in a number and
 * mean opposite things: "you have none" invites generating, and "I cannot tell
 * what you have" must not.
 *
 * When a worker starts attributing individual assets to individual plans, this
 * is the class that has to read that attribution — two plans on one profile
 * share stock today, which is right for two campaigns for one imprint and
 * wrong the moment they are meant to be independent.
 */
final readonly class TakeProductionInventory
{
    public function handle(ProductionPlan $plan): ProductionInventory
    {
        $artistId = $plan->editorialProfile?->default_artist_id;

        if ($artistId === null) {
            // Not zero. Zero would invite generating; this says the question
            // cannot be answered, and the caller refuses rather than guesses.
            return new ProductionInventory(
                onHand: 0,
                inFlight: 0,
                target: $plan->target_track_count,
                attributable: false,
            );
        }

        return new ProductionInventory(
            onHand: $this->onHand($artistId),
            inFlight: $this->inFlight($plan, $artistId),
            target: $plan->target_track_count,
        );
    }

    /**
     * Finished, unreleased, and available to use.
     *
     * A **released** track is spent: it did its job. Counting it would make a
     * plan stop producing because it once had enough rather than because it
     * has enough — and a plan that released everything would then never
     * produce again.
     */
    private function onHand(int $artistId): int
    {
        return Track::query()
            ->whereIn('status', [TrackStatus::Draft->value, TrackStatus::Ready->value])
            ->whereDoesntHave('releases')
            // Typed against the relation's own builder, so static analysis
            // can see that a qualified column name is intended here: the
            // closure runs against `artists` joined through `track_artist`,
            // and both names have to be spelled out because `id` is ambiguous
            // across the join.
            ->whereHas('artists', static function (Builder $query) use ($artistId): void {
                $query->getQuery()
                    ->where('artists.id', $artistId)
                    ->where('track_artist.role', TrackArtistRole::Primary->value);
            })
            ->count();
    }

    /**
     * Asked for and not yet finished.
     *
     * Three sources, because work is in flight in three different places and a
     * count that missed any of them would order more of it:
     *
     *   - generations a provider has not answered yet;
     *   - candidates waiting for a person to promote them;
     *   - slots open or claimed, which are occasions already committed.
     */
    private function inFlight(ProductionPlan $plan, int $artistId): int
    {
        $generating = MusicGeneration::query()
            ->whereIn('status', [
                MusicGenerationStatus::Queued->value,
                MusicGenerationStatus::Processing->value,
            ])
            ->count();

        $awaitingReview = TrackCandidate::query()
            ->whereIn('status', [
                TrackCandidateStatus::Pending->value,
                TrackCandidateStatus::Processing->value,
                TrackCandidateStatus::NeedsReview->value,
                TrackCandidateStatus::Ready->value,
            ])
            ->count();

        $committed = ProductionSlot::query()
            ->where('production_plan_id', $plan->id)
            ->whereIn('status', [
                ProductionSlotStatus::Pending->value,
                ProductionSlotStatus::Claimed->value,
            ])
            ->count();

        return $generating + $awaitingReview + $committed;
    }
}
