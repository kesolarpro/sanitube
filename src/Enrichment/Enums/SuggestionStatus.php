<?php

declare(strict_types=1);

namespace SaniTube\Enrichment\Enums;

/**
 * What has happened to a suggestion since a model made it.
 *
 * **Every suggestion is written `PROPOSED`, including a confident one.** ADR-0016
 * places these at the weakest of the three levels of truth: they were produced
 * by a model that was not trained on this catalogue, cannot be asked why, and
 * is reliably wrong about proper nouns. A confidence a model reports about its
 * own output is not evidence — it is more output.
 *
 * Auto-accepting the confident half is the specific mistake this enum exists to
 * prevent. It teaches an operator that the review queue holds only hard cases,
 * which is precisely when a wrong title reaches a distributor unread.
 */
enum SuggestionStatus: string
{
    /** A model said it. Nobody has looked. */
    case Proposed = 'PROPOSED';

    /**
     * A person read it and put it into the catalogue — through the ordinary
     * domain services, never by this row being copied into a track.
     */
    case Accepted = 'ACCEPTED';

    /**
     * A person read it and said no. Kept rather than deleted: a run of
     * rejections is how a bad prompt version announces itself, and a deleted
     * one cannot be counted.
     */
    case Rejected = 'REJECTED';

    /**
     * A newer suggestion for the same asset and task exists.
     *
     * Not the same as rejected. Nobody disagreed with this one; it was simply
     * overtaken — by a new prompt version, a different model, or better
     * evidence — and keeping it is what makes "what did the old prompt say"
     * answerable.
     */
    case Superseded = 'SUPERSEDED';

    public function isDecided(): bool
    {
        return $this === self::Accepted || $this === self::Rejected;
    }
}
