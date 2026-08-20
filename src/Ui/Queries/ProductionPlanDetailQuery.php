<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use App\Models\User;
use SaniTube\MusicGeneration\Models\MusicGeneration;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionPlan;
use SaniTube\Production\Models\ProductionSlot;

/**
 * One plan, and every occasion it has opened.
 *
 * This is the screen that answers **"why did nothing happen on the 14th"** — the
 * question the slot lifecycle was designed around and which, until now, only a
 * database client could answer. A skip carries the reason that produced it, a
 * failure carries its own, and the two are never mixed: an inventory that found
 * enough is the system working, and a list that filed those under failures
 * would teach an operator to ignore the failure list.
 *
 * **Three things the row has that never reach the browser**, and each is the
 * kind that arrives by accident:
 *
 *   - `provider_job_id` — the handle a supplier's own API uses, and on a
 *     query-string-authenticated provider that string is a credential.
 *   - the generation's `prompt` and `lyrics` — the operator's own work, and a
 *     slot list is not where it gets copied to.
 *   - `attempted_providers` in full is shown, because which suppliers were
 *     asked is operational fact rather than a secret — but only names, and
 *     names are already in the operator's own configuration.
 *
 * What does reach the browser about a generation is its uuid and its state,
 * which is exactly enough to open the studio screen that owns it.
 */
final readonly class ProductionPlanDetailQuery
{
    public const OCCASIONS = 60;

    /**
     * @return array<string, mixed>|null null when there is no such plan
     */
    public function get(string $uuid, ?User $viewer = null): ?array
    {
        $plan = ProductionPlan::query()
            ->with('editorialProfile')
            ->where('uuid', $uuid)
            ->first();

        if (! $plan instanceof ProductionPlan) {
            return null;
        }

        return [
            'plan' => [
                'uuid' => $plan->uuid,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'status' => $plan->status->value,
                'autonomy_mode' => $plan->autonomy_mode->value,
                'may_generate_unattended' => $plan->mayGenerateUnattended(),
                'is_runnable' => $plan->status->isRunnable(),
                'is_resumable' => $plan->status->isResumable(),
                'was_stopped_by_the_platform' => $plan->status->wasSetByThePlatform(),
                'target_track_count' => $plan->target_track_count,
                'cadence_days' => $plan->cadence_days,
                'notes' => $plan->notes,
                'halted_reason' => $plan->halted_reason,
                'halted_at' => $plan->halted_at?->toIso8601String(),
                'editorial_profile' => $plan->editorialProfile?->name,
                'created_at' => $plan->created_at?->toIso8601String(),
            ],
            'occasions' => $this->occasions($plan),
            // Both halves fail for different reasons and the screen says
            // which: a MEMBER may watch a plan and may not steer it, and a
            // finished plan cannot be steered by anybody.
            'may_steer' => $viewer?->role->canWriteCatalogue() ?? false,
        ];
    }

    /**
     * The plan's occasions, newest first.
     *
     * Bounded rather than paginated: a plan opens one occasion per cadence, so
     * sixty is more than a year of a weekly plan, and a cursor on a list that
     * short would be scaffolding nobody uses. Ordered by date and then by id,
     * which is ADR-0017's tiebreak rule — two occasions on the same day would
     * otherwise come back in whichever order the engine chose today.
     *
     * @return list<array<string, mixed>>
     */
    private function occasions(ProductionPlan $plan): array
    {
        $slots = ProductionSlot::query()
            ->with('generation')
            ->where('production_plan_id', $plan->id)
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->limit(self::OCCASIONS)
            ->get();

        $rows = [];

        foreach ($slots as $slot) {
            $generation = $slot->generation;

            $rows[] = [
                'uuid' => $slot->uuid,
                'scheduled_for' => $slot->scheduled_for->toDateString(),
                'status' => $slot->status->value,
                'intent' => $slot->intent,
                // A skip is information and a failure is something to look at.
                // Kept apart here so a screen cannot accidentally merge them.
                'was_skipped' => $slot->status === ProductionSlotStatus::Skipped,
                'was_set_by_a_person' => $slot->status->wasSetByAPerson(),
                'outcome_reason' => $slot->outcome_reason,
                'claimed_by' => $slot->claimed_by,
                'claimed_at' => $slot->claimed_at?->toIso8601String(),
                'settled_at' => $slot->settled_at?->toIso8601String(),
                // Names only. Which suppliers were asked is operational fact;
                // how to reach them is not, and no endpoint or key is here.
                'attempted_providers' => $slot->attempted(),
                'generation' => $generation instanceof MusicGeneration ? [
                    // Enough to open the studio screen that owns it, and
                    // nothing more. No provider job id, no prompt, no lyrics.
                    'uuid' => $generation->uuid,
                    'status' => $generation->status->value,
                    'provider' => $generation->provider,
                ] : null,
                'is_cancellable' => ! $slot->status->isSettled(),
            ];
        }

        return $rows;
    }
}
