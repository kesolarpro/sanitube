<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Observers;

use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Events\TrackArchived;
use SaniTube\Catalog\Events\TrackCreated;
use SaniTube\Catalog\Exceptions\TrackDeletionException;
use SaniTube\Catalog\Exceptions\TrackLanguageException;
use SaniTube\Catalog\Models\Track;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Releases\Models\Release;

/**
 * Enforces the track invariants (I6, I8).
 */
final class TrackObserver
{
    /**
     * I8 — instrumental and language must agree.
     *
     * ISO 639-2 already has a code for "no linguistic content", so an
     * instrumental has exactly one correct language and a track in that
     * language is by definition an instrumental. Letting the two drift
     * produces releases that DSPs reject for lyrics metadata on a track that
     * has no lyrics.
     */
    public function saving(Track $track): void
    {
        $isInstrumental = (bool) $track->is_instrumental;
        $isNoLinguisticContent = ContentLanguage::tryFromCode($track->language_code)?->isInstrumental() === true;

        if ($isInstrumental !== $isNoLinguisticContent) {
            throw TrackLanguageException::instrumentalMismatch($isInstrumental, (string) $track->language_code);
        }
    }

    public function created(Track $track): void
    {
        event(new TrackCreated($track));
    }

    public function updated(Track $track): void
    {
        if ($track->wasChanged('status') && $track->status === TrackStatus::Archived) {
            event(new TrackArchived($track));
        }
    }

    /**
     * I6 — a track that is part of a committed release cannot be removed.
     */
    public function deleting(Track $track): void
    {
        /** @var list<string> $committed */
        $committed = Release::query()
            ->whereIn('id', $track->releases()->select('releases.id'))
            ->get()
            ->filter(fn (Release $release): bool => $release->status->isCommitted())
            ->map(fn (Release $release): string => $release->uuid)
            ->values()
            ->all();

        if ($committed !== []) {
            throw TrackDeletionException::committedToReleases($track->uuid, $committed);
        }
    }
}
