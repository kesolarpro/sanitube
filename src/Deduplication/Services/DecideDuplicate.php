<?php

declare(strict_types=1);

namespace SaniTube\Deduplication\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SaniTube\Assets\Services\TrashAsset;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Deduplication\Enums\DuplicateDecision;
use SaniTube\Deduplication\Exceptions\DuplicateDecisionException;
use SaniTube\Deduplication\Models\DuplicateRelation;

/**
 * A person answering what the platform proposed.
 *
 * The other half of ADR-0016. `EvaluateAssetDuplicates` may only ever write
 * `PROPOSED`; this is the only way a finding becomes anything else, and it
 * requires a named user. There is no configuration that lets a threshold
 * decide, and no path from a similarity score to this class.
 *
 * **Confirming is not deleting.** It records that a reviewer agrees two assets
 * hold the same recording, and stops. What happens to the redundant copy is a
 * separate act — {@see TrashAsset} — with its own
 * refusals, its own audit line, and its own reversal. Keeping them apart means
 * an over-eager confirmation costs nothing, which is what makes the queue safe
 * to work through quickly.
 *
 * A rejection is the more interesting outcome to record: it is a person saying
 * the platform was wrong, and a run of them is how a badly set threshold
 * announces itself.
 */
final readonly class DecideDuplicate
{
    public function __construct(private RecordAuditEvent $audit) {}

    public function confirm(DuplicateRelation $relation, User $by): DuplicateRelation
    {
        return $this->decide($relation, DuplicateDecision::Confirmed, $by, AuditAction::DuplicateConfirmed);
    }

    public function reject(DuplicateRelation $relation, User $by): DuplicateRelation
    {
        return $this->decide($relation, DuplicateDecision::Rejected, $by, AuditAction::DuplicateRejected);
    }

    private function decide(
        DuplicateRelation $relation,
        DuplicateDecision $decision,
        User $by,
        AuditAction $action,
    ): DuplicateRelation {
        // Changing an answer is allowed -- a reviewer who confirms the wrong
        // row must be able to say so, and nothing irreversible happened. Only
        // repeating the same answer is refused, so that a double-submitted
        // form does not rewrite who decided and when.
        if ($relation->decision === $decision) {
            throw DuplicateDecisionException::alreadyDecided($decision->value);
        }

        DB::transaction(function () use ($relation, $decision, $by): void {
            $relation->forceFill([
                'decision' => $decision,
                'decided_by_user_id' => $by->id,
                'decided_at' => Carbon::now(),
            ])->save();
        });

        $this->audit->record(
            $action,
            subjectUuid: $relation->uuid,
            context: [
                // Which two assets, so the line is readable without joining
                // back to a table whose row may since have been re-decided.
                'asset' => $relation->asset?->uuid,
                'related_asset' => $relation->relatedAsset?->uuid,
                'level' => $relation->level->value,
                'basis' => $relation->basis->value,
                'similarity' => $relation->similarity(),
            ],
        );

        return $relation->refresh();
    }
}
