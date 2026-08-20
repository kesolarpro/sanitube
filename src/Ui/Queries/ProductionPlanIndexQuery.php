<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SaniTube\Production\Enums\AutonomyMode;
use SaniTube\Production\Enums\ProductionPlanStatus;
use SaniTube\Production\Enums\ProductionSlotStatus;
use SaniTube\Production\Models\ProductionPlan;

/**
 * What the platform has been told to do on its own, and what came of it.
 *
 * PROD-001 through PROD-004 built a planner that decides unattended that more
 * music should exist and then pays a supplier for it. Until this screen the
 * only way to see any of that was `artisan` and a database client — which meant
 * the one part of SaniTube that acts without a person present was also the one
 * part a person could not watch.
 *
 * **Every outcome is present, including the zeros.** A plan that has skipped
 * forty occasions and failed none looks identical to one that has done nothing
 * if absent keys are rendered as absent; the difference is the whole reason
 * `SKIPPED` and `FAILED` are separate states.
 *
 * Counted in one grouped query rather than per row. A plan list that issued a
 * query per plan would be the N+1 PERF-001 spent a ticket removing everywhere
 * else, and slots are the table that grows fastest here — one row per plan per
 * cadence, for ever.
 */
final readonly class ProductionPlanIndexQuery
{
    public const PER_PAGE = 25;

    /**
     * @return array<string, mixed>
     */
    public function paginate(?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $page = ProductionPlan::query()
            ->with('editorialProfile')
            ->orderBy('created_at')
            ->orderBy('uuid')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

        return [
            'rows' => $this->rows($page),
            'next_cursor' => $page->nextCursor()?->encode(),
            'previous_cursor' => $page->previousCursor()?->encode(),
            'per_page' => $perPage,
        ];
    }

    /**
     * The vocabulary a screen needs to render a filter or a form without
     * hard-coding strings the enums own.
     *
     * @return array<string, list<string>>
     */
    public function options(): array
    {
        return [
            'status' => array_map(
                static fn (ProductionPlanStatus $case): string => $case->value,
                ProductionPlanStatus::cases(),
            ),
            'autonomy' => array_map(
                static fn (AutonomyMode $case): string => $case->value,
                AutonomyMode::cases(),
            ),
            'outcome' => array_map(
                static fn (ProductionSlotStatus $case): string => $case->value,
                ProductionSlotStatus::cases(),
            ),
        ];
    }

    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['created_at', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, ProductionPlan>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        /** @var list<ProductionPlan> $plans */
        $plans = $page->items();

        $outcomes = $this->outcomes(array_map(static fn (ProductionPlan $plan): int => $plan->id, $plans));

        $rows = [];

        foreach ($plans as $plan) {
            $rows[] = [
                'uuid' => $plan->uuid,
                'name' => $plan->name,
                'status' => $plan->status->value,
                'autonomy_mode' => $plan->autonomy_mode->value,
                // Two different questions with two different answers. A plan
                // can be granted autonomy and be paused, and an operator
                // looking at a quiet month needs to know which one it is.
                'may_generate_unattended' => $plan->mayGenerateUnattended(),
                'is_runnable' => $plan->status->isRunnable(),
                'is_resumable' => $plan->status->isResumable(),
                'was_stopped_by_the_platform' => $plan->status->wasSetByThePlatform(),
                'target_track_count' => $plan->target_track_count,
                'cadence_days' => $plan->cadence_days,
                'halted_reason' => $plan->halted_reason,
                'halted_at' => $plan->halted_at?->toIso8601String(),
                'editorial_profile' => $plan->editorialProfile?->name,
                'occasions' => $outcomes[$plan->id] ?? self::emptyOutcomes(),
                'created_at' => $plan->created_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * Slot outcomes per plan, in one query.
     *
     * @param  list<int>  $planIds
     * @return array<int, array<string, int>>
     */
    private function outcomes(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        $counted = [];

        $rows = DB::table('production_slots')
            ->select('production_plan_id', 'status', DB::raw('count(*) as total'))
            ->whereIn('production_plan_id', $planIds)
            ->groupBy('production_plan_id', 'status')
            ->get();

        foreach ($rows as $row) {
            $planId = (int) $row->production_plan_id;
            $status = is_string($row->status) ? $row->status : '';

            $counted[$planId] ??= self::emptyOutcomes();

            if (array_key_exists($status, $counted[$planId])) {
                $counted[$planId][$status] = (int) $row->total;
                $counted[$planId]['total'] += (int) $row->total;
            }
        }

        return $counted;
    }

    /**
     * Every state at zero.
     *
     * Absent keys would make a plan that has skipped forty occasions and failed
     * none look like one that has done nothing.
     *
     * @return array<string, int>
     */
    private static function emptyOutcomes(): array
    {
        $counts = ['total' => 0];

        foreach (ProductionSlotStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }

        return $counts;
    }

    private function validCursor(?string $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        // A cursor from another list decodes into column names this ordering
        // does not have, and the paginator would silently return the first
        // page. Refused, so the screen says so rather than lying.
        if (! self::describesThisOrdering($cursor)) {
            throw new InvalidArgumentException('CURSOR_NOT_FOR_THIS_LIST');
        }

        return $cursor;
    }
}
