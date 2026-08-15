<?php

declare(strict_types=1);

namespace SaniTube\Releases\Observers;

use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Events\ReleaseCreated;
use SaniTube\Releases\Exceptions\ReleaseDeletionException;
use SaniTube\Releases\Models\Release;

/**
 * Enforces the release invariants (I6).
 */
final class ReleaseObserver
{
    public function created(Release $release): void
    {
        event(new ReleaseCreated($release));
    }

    /**
     * I6 — only a draft can be removed. Anything beyond DRAFT has either been
     * approved internally or handed to a distributor, and in both cases the
     * catalogue must keep the record.
     */
    public function deleting(Release $release): void
    {
        if ($release->status !== ReleaseStatus::Draft) {
            throw ReleaseDeletionException::notDraft($release->uuid, $release->status);
        }
    }
}
