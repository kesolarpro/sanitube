<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Services;

use SaniTube\Ingestion\Exceptions\CandidateReviewException;
use SaniTube\Ingestion\Models\TrackCandidate;

/**
 * Corrects a suggestion before anyone acts on it.
 *
 * `suggested_title` is a guess made from a filename and is frequently wrong.
 * Without a way to fix it in place, a reviewer's only options are to promote
 * something mistitled and correct the catalogue afterwards, or to retype the
 * title into every promotion — and the catalogue is the wrong place to be
 * fixing an import's guesses.
 *
 * A decided candidate is not revisable. Editing the suggestion behind a
 * promoted candidate would silently rewrite the history of a decision that was
 * taken on the old value.
 */
final readonly class ReviseTrackCandidate
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(TrackCandidate $candidate, array $attributes): TrackCandidate
    {
        if (! $candidate->status->isRevisable()) {
            throw CandidateReviewException::alreadyDecided($candidate->status);
        }

        $changes = [];

        if (array_key_exists('suggested_title', $attributes)) {
            $title = $attributes['suggested_title'];
            $changes['suggested_title'] = is_string($title) && trim($title) !== '' ? trim($title) : null;
        }

        if (array_key_exists('metadata', $attributes) && is_array($attributes['metadata'])) {
            // Merged, not replaced. Whatever the importer read out of the file
            // is as much a part of the record as what a reviewer adds, and a
            // PATCH that silently discarded it would lose the only copy.
            $changes['metadata'] = array_merge($candidate->metadata ?? [], $attributes['metadata']);
        }

        if ($changes === []) {
            return $candidate;
        }

        $candidate->forceFill($changes)->save();

        return $candidate->refresh();
    }
}
