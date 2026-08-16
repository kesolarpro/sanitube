<?php

declare(strict_types=1);

namespace SaniTube\Ui\Queries;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Enums\AuditOutcome;
use SaniTube\Audit\Enums\AuditSubject;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Audit\Support\Redaction;

/**
 * The audit log, newest first.
 *
 * **Reading it is not itself audited.** A log that records its own readers
 * grows by being looked at, and on a platform where reading is already bounded
 * by the administer role, the only thing that would tell an investigator is
 * that somebody investigated.
 *
 * The actor is rendered from `actor_label` and `actor_role` — the snapshot
 * taken at write time — rather than from a join. That is not an optimisation:
 * a line about a person who has since been renamed should say who they were
 * when they did it, and a join would quietly rewrite the past every time
 * somebody edits their profile. The uuid goes out too, so a screen can group
 * by person, but never the email address: the log outlives the account.
 *
 * `context` is published as stored. It has already been through
 * {@see Redaction}, which is the one place that
 * decides what an audit line may carry; re-filtering here would be a second
 * copy of that judgement, and the second copy is the one that eventually
 * disagrees.
 */
final readonly class AuditIndexQuery
{
    public const PER_PAGE = 50;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(array $filters = [], ?string $cursor = null, int $perPage = self::PER_PAGE): array
    {
        $cursor = $this->validCursor($cursor);

        $query = AuditEvent::query();

        $this->applyFilters($query, $filters);

        $page = $query
            // Newest first, which is the order an incident is read in. `uuid`
            // breaks ties: v7 is time-ordered, so two events in the same
            // second still come back in the order they happened.
            ->orderByDesc('occurred_at')
            ->orderByDesc('uuid')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

        return [
            'rows' => $this->rows($page),
            'next_cursor' => $page->nextCursor()?->encode(),
            'previous_cursor' => $page->previousCursor()?->encode(),
            'per_page' => $perPage,
        ];
    }

    /**
     * What the filters can be set to.
     *
     * From the enums rather than from the table: an action nobody has
     * performed yet is still a thing this platform can record, and a filter
     * list built from `SELECT DISTINCT action` would silently shrink as
     * history is pruned.
     *
     * @return array<string, list<string>>
     */
    public function options(): array
    {
        return [
            'action' => array_map(static fn (AuditAction $case): string => $case->value, AuditAction::cases()),
            'subject' => array_map(static fn (AuditSubject $case): string => $case->value, AuditSubject::cases()),
            'outcome' => array_map(static fn (AuditOutcome $case): string => $case->value, AuditOutcome::cases()),
        ];
    }

    public static function describesThisOrdering(string $cursor): bool
    {
        return CursorShape::carries($cursor, ['occurred_at', 'uuid']);
    }

    /**
     * @param  CursorPaginator<int, AuditEvent>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(CursorPaginator $page): array
    {
        $rows = [];

        foreach ($page->items() as $event) {
            $rows[] = [
                'uuid' => $event->uuid,
                'action' => $event->action->value,
                'subject' => $event->subject->value,
                'subject_uuid' => $event->subject_uuid,
                'outcome' => $event->outcome->value,
                'reason' => $event->reason,
                'significant' => $event->action->isSecuritySignificant(),
                'actor' => [
                    'kind' => $event->actor_kind->value,
                    // The snapshot, not the current name. See the class
                    // comment.
                    'label' => $event->actor_label,
                    'role' => $event->actor_role,
                ],
                'ip_address' => $event->ip_address,
                'user_agent' => $event->user_agent,
                'context' => $event->context,
                'occurred_at' => $event->occurred_at->toAtomString(),
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $action = $filters['action'] ?? null;

        if (is_string($action) && $action !== '') {
            // Through the enum, so an unknown value is a 422 from the request
            // rather than a `LIKE` somebody can shape.
            $query->where('action', AuditAction::from($action)->value);
        }

        $subject = $filters['subject'] ?? null;

        if (is_string($subject) && $subject !== '') {
            $query->where('subject', AuditSubject::from($subject)->value);
        }

        $outcome = $filters['outcome'] ?? null;

        if (is_string($outcome) && $outcome !== '') {
            $query->where('outcome', AuditOutcome::from($outcome)->value);
        }

        $subjectUuid = $filters['subject_uuid'] ?? null;

        if (is_string($subjectUuid) && $subjectUuid !== '') {
            // Equality, never a prefix match: this is how "everything ever
            // done to this release" is asked, and a partial uuid would answer
            // about somebody else's.
            $query->where('subject_uuid', $subjectUuid);
        }
    }

    private function validCursor(?string $cursor): ?string
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        if (! self::describesThisOrdering($cursor)) {
            throw new InvalidArgumentException('cursor');
        }

        return $cursor;
    }
}
