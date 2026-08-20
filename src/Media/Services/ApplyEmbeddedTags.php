<?php

declare(strict_types=1);

namespace SaniTube\Media\Services;

use SaniTube\Ingestion\Models\TrackCandidate;
use SaniTube\Ingestion\Services\SuggestTitle;
use SaniTube\Media\EmbeddedTags;
use SaniTube\Media\Models\AudioAnalysis;

/**
 * What the file says about itself, offered to the person reviewing it.
 *
 * TAG-001. Two rules, and the second is the whole ticket.
 *
 * **Everything a file claims is recorded.** The tags go into the candidate's
 * `metadata` under their own key, so the review screen can show "what the file
 * says" beside "what the platform measured" and a person can see them disagree.
 * Nothing is thrown away for being unlikely.
 *
 * **A tag never overwrites a person.** `suggested_title` is filled from the
 * embedded title only when it is still the machine's own guess — absent, or
 * exactly what `SuggestTitle` derives from the filename. If a reviewer typed
 * something, or a manifest supplied it, that survives.
 *
 * The comparison against `SuggestTitle::from()` is the signal, and it needs no
 * new column: that function is deterministic, so a title equal to its output is
 * one nobody has touched. A `revised_at` flag would be a second way of saying
 * the same thing, and the second way is the one that gets forgotten when
 * somebody adds a third path that edits a candidate.
 *
 * **Idempotent.** Re-analysing a file re-reads the same tags and reaches the
 * same conclusion — including the conclusion that a human title must be left
 * alone, because after the first pass the title no longer matches the
 * filename guess and is therefore protected from this service as well.
 */
final readonly class ApplyEmbeddedTags
{
    /** The key the review screen reads. Namespaced so a manifest cannot collide with it. */
    public const METADATA_KEY = 'embedded_tags';

    public function handle(TrackCandidate $candidate, ?AudioAnalysis $analysis): TrackCandidate
    {
        if (! $analysis instanceof AudioAnalysis) {
            return $candidate;
        }

        $raw = $analysis->raw;

        if (! is_array($raw)) {
            return $candidate;
        }

        $tags = EmbeddedTags::fromProbe($raw);

        if ($tags->isEmpty()) {
            return $candidate;
        }

        $changes = [
            'metadata' => [
                ...($candidate->metadata ?? []),
                self::METADATA_KEY => $tags->toArray(),
            ],
        ];

        if ($tags->title !== null && $this->titleIsStillAGuess($candidate)) {
            // A better guess replacing a worse one. "01_-_final_MASTER_v3.wav"
            // tidied into "final MASTER v3" is what the filename can offer; the
            // title the file carries is what somebody typed when they made it.
            $changes['suggested_title'] = $tags->title;
        }

        $candidate->forceFill($changes)->save();

        return $candidate->refresh();
    }

    /**
     * Whether the current suggestion is still the platform's own.
     *
     * Null counts: a candidate whose filename yielded nothing has no title to
     * protect.
     */
    private function titleIsStillAGuess(TrackCandidate $candidate): bool
    {
        return $candidate->suggested_title === null
            || $candidate->suggested_title === SuggestTitle::from($candidate->original_filename);
    }
}
