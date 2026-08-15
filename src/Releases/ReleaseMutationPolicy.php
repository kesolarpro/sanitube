<?php

declare(strict_types=1);

namespace SaniTube\Releases;

use SaniTube\Releases\Enums\ReleaseStatus;

/**
 * What may still be changed, and when.
 *
 * ARCH-002 gave a release a status; it did not say which edits each status
 * permits, and "editable" turns out to mean three different things:
 *
 *   - **DRAFT** — everything. Nobody outside SaniTube has seen this.
 *   - **READY** — nothing structural. READY is a statement that the package
 *     passed validation, and quietly adding a track afterwards would leave a
 *     release marked ready that never was. It can be *reopened* to DRAFT,
 *     which is an explicit act that records the retraction.
 *   - **SUBMITTED / DELIVERED / LIVE** — nothing at all, and no reopening.
 *     The tracks exist outside SaniTube now: a store is serving them and a
 *     royalty statement will reference them. Editing the local record would
 *     make it disagree with what the world already has.
 *   - **TAKEN_DOWN** — nothing. The history of what was delivered is the
 *     point of keeping the row.
 *
 * Stated as a policy object rather than scattered `if` statements so the rule
 * is in one place and can be asked, rather than re-derived at each call site.
 */
final readonly class ReleaseMutationPolicy
{
    /**
     * Whether the tracklist, artists, cover or metadata may be changed.
     */
    public static function allowsStructuralChange(ReleaseStatus $status): bool
    {
        return $status === ReleaseStatus::Draft;
    }

    /**
     * Whether READY can be retracted back to DRAFT.
     *
     * Only from READY. A committed release cannot be reopened, because the
     * recordings are already out there and a local edit would not reach them.
     */
    public static function allowsReopen(ReleaseStatus $status): bool
    {
        return $status === ReleaseStatus::Ready;
    }

    /**
     * A sentence explaining the refusal, for the caller who has to act on it.
     */
    public static function refusalReason(ReleaseStatus $status): string
    {
        return match (true) {
            $status === ReleaseStatus::Ready => 'This release is READY. Reopen it to DRAFT first — '
                .'changing it in place would leave a release marked ready that never passed validation '
                .'in the state it is now in.',
            $status->isCommitted() => 'This release has been handed to a distributor. Its recordings '
                .'exist outside SaniTube, and editing the local record would make it disagree with '
                .'what stores are already serving.',
            default => sprintf('A release in [%s] cannot be changed.', $status->value),
        };
    }
}
