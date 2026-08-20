<?php

declare(strict_types=1);

namespace SaniTube\Transcription;

/**
 * What a transcription supplier said about one file.
 *
 * **A suggestion, in the sense of ADR-0016** — the weakest of the three levels
 * of truth. It was produced by a model that was not trained on this catalogue,
 * cannot be asked why, and will be confidently wrong about proper nouns, about
 * any language it was not expecting, and about singing in general. Nothing here
 * may become catalogue data without a person.
 *
 * `languageCode` is the provider's guess and is stored as one. It is **not**
 * the track's language: a release's language is an editorial decision with
 * distribution consequences, and a model that heard mostly instrumental will
 * happily report English.
 *
 * `text` and `segments` are both kept. The flat text is what a person reads and
 * what a search looks at; the segments are what makes a transcript usable for
 * anything timed, and recomputing them from the text is impossible. Providers
 * that return no segments are honest about it rather than having one invented.
 */
final readonly class TranscriptionResult
{
    /**
     * @param  list<TranscriptSegmentResult>  $segments
     */
    public function __construct(
        public string $text,
        public array $segments = [],
        public ?string $languageCode = null,
        public ?string $model = null,
        public ?int $durationSeconds = null,
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }
}
