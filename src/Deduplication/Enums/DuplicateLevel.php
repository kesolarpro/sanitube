<?php

declare(strict_types=1);

namespace SaniTube\Deduplication\Enums;

/**
 * How strong the evidence is that two assets are the same thing.
 *
 * Three levels and no more. A finer scale would suggest the platform can tell
 * a remix from a re-master from an alternate take, and it cannot — that is a
 * research problem, and on a catalogue built largely from one artist's work in
 * one style it would produce mostly confident nonsense. Deliberately absent,
 * and see ADR-0016 for what it would take to add.
 *
 * The levels are not a confidence percentage rounded into buckets. They differ
 * in **what kind of claim they are**, which is why they are named rather than
 * numbered: the first is a measurement and the other two are proposals, and no
 * amount of raising a threshold turns the second into the first.
 */
enum DuplicateLevel: string
{
    /**
     * The same bytes. A SHA-256 collision is not a thing that happens by
     * accident, so this is observed, not inferred, and no threshold is
     * involved in reaching it.
     */
    case ExactDuplicate = 'EXACT_DUPLICATE';

    /**
     * Different bytes, the same audio: a WAV and an MP3 of one master, or one
     * file exported twice. Inferred from acoustic agreement above a configured
     * threshold, so it is a **proposal** — strong, and still a proposal.
     */
    case SameRecording = 'SAME_RECORDING';

    /**
     * Enough agreement to be worth a person's attention and not enough to
     * claim anything. It means "look at these two", never "these are related".
     */
    case PossibleRelation = 'POSSIBLE_RELATION';

    /**
     * Whether this level was observed rather than inferred.
     *
     * The distinction that decides what the platform may do on its own. A
     * measurement can be stated as fact in the interface; a proposal has to be
     * shown as one, with the evidence beside it, and a person decides.
     */
    public function isMeasured(): bool
    {
        return $this === self::ExactDuplicate;
    }
}
