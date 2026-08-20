<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Enums;

/**
 * Where one artwork generation request has got to.
 *
 * The same vocabulary `MusicGenerationStatus` uses, for the same reason: an
 * operator reading two queues in one platform should not have to learn two
 * words for the same state.
 *
 * `PENDING` and `SUBMITTED` are deliberately distinct, and the distinction is
 * what the spend guard counts. A request that never reached a provider cost
 * nothing, whatever else went wrong with it.
 */
enum ArtworkGenerationStatus: string
{
    /** Recorded, claimed by nobody, nothing sent. */
    case Pending = 'PENDING';

    /** A worker holds the claim and the request has left this server. */
    case Submitted = 'SUBMITTED';

    /** An image came back and an asset exists for it. */
    case Completed = 'COMPLETED';

    /** The provider, or this platform, refused. `failure_code` says which. */
    case Failed = 'FAILED';

    /** Withdrawn before submission. Never counted, never retried. */
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed || $this === self::Cancelled;
    }

    /**
     * Whether this request may be tried again.
     *
     * Only a failure. A cancelled request was a decision, and re-running it
     * would undo the decision rather than repeat the attempt.
     */
    public function isRetryable(): bool
    {
        return $this === self::Failed;
    }
}
