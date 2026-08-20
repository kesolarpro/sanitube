<?php

declare(strict_types=1);

namespace SaniTube\Transcription;

/**
 * One stretch of speech, as a provider reported it.
 *
 * Timings are milliseconds from the start of the audio the provider actually
 * decoded — which is not always the whole file, and is why the transcript
 * records what it was taken over.
 *
 * A segment is deliberately not a "line" or a "lyric". Providers segment on
 * silence, on punctuation, or on a fixed window, and none of those is a
 * lyrical line. Calling it a line here would make every downstream screen
 * quietly assume a structure the audio never had.
 */
final readonly class TranscriptSegmentResult
{
    public function __construct(
        public int $startMs,
        public int $endMs,
        public string $text,
    ) {}
}
