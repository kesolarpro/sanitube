<?php

declare(strict_types=1);

namespace SaniTube\Enrichment\Exceptions;

use RuntimeException;
use SaniTube\Enrichment\Enums\SuggestionStatus;

/**
 * A decision the platform declined to record.
 *
 * Machine-readable, like every refusal here. The English is for a log.
 */
final class SuggestionDecisionException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * Somebody has already answered this one.
     *
     * Guarded because a resubmitted form would otherwise rewrite who decided
     * and when — and, worse, would apply the suggestion to the catalogue a
     * second time over whatever a person has edited since.
     */
    public static function alreadyDecided(SuggestionStatus $status): self
    {
        return new self(
            sprintf('This suggestion is already %s.', $status->value),
            'SUGGESTION_ALREADY_DECIDED',
        );
    }

    /**
     * The asset is not the master of any track.
     *
     * **Not a gap to be filled by creating one.** A suggestion describes audio;
     * a track is a catalogue entry with a lineage, a status and a promotion
     * decision behind it. Letting an accepted suggestion conjure one would make
     * a model the author of catalogue entries, which is exactly the boundary
     * ADR-0016 draws. Promotion is where a track comes from.
     */
    public static function noTrack(): self
    {
        return new self('This asset is not the master of any track.', 'SUGGESTION_NO_TRACK');
    }

    /**
     * Accepting nothing is not accepting.
     *
     * A reviewer who cleared every field and pressed accept meant to reject,
     * and recording it as an acceptance that wrote no catalogue data would
     * leave a row claiming a model's answer was used when it was not.
     */
    public static function nothingToAccept(): self
    {
        return new self('An acceptance must carry at least one value.', 'SUGGESTION_NOTHING_TO_ACCEPT');
    }
}
