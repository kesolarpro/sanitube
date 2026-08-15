<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Exceptions;

use RuntimeException;
use SaniTube\Ingestion\Enums\TrackCandidateStatus;

/**
 * A review decision the platform will not carry out.
 *
 * Every case below is a refusal to write to the catalogue, so each carries a
 * machine-readable `reason` that the API surfaces. "422 Unprocessable" with no
 * reason is what makes a reviewer guess, and a reviewer who guesses will
 * eventually work around the check rather than address it.
 */
final class CandidateReviewException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function notPromotable(TrackCandidateStatus $status): self
    {
        return new self(
            sprintf(
                'A candidate in [%s] cannot be promoted. Only READY may be promoted outright; '
                    .'NEEDS_REVIEW and DUPLICATE require an explicit override with a note.',
                $status->value,
            ),
            'NOT_PROMOTABLE',
        );
    }

    public static function overrideNeedsANote(TrackCandidateStatus $status): self
    {
        return new self(
            sprintf(
                'Overruling [%s] requires a note saying why. An override nobody can account for '
                    .'six months later is not an override, it is a gap.',
                $status->value,
            ),
            'OVERRIDE_NEEDS_NOTE',
        );
    }

    public static function alreadyPromoted(string $uuid): self
    {
        return new self(
            sprintf('Candidate [%s] has already been promoted. Promotion happens once.', $uuid),
            'ALREADY_PROMOTED',
        );
    }

    public static function alreadyDecided(TrackCandidateStatus $status): self
    {
        return new self(
            sprintf('A candidate in [%s] has already been decided and cannot be changed.', $status->value),
            'ALREADY_DECIDED',
        );
    }

    public static function assetNotVerified(string $uuid): self
    {
        return new self(
            sprintf(
                'The master behind candidate [%s] has not been verified. Promoting it would put a '
                    .'recording in the catalogue that nobody has confirmed is the one it means.',
                $uuid,
            ),
            'ASSET_NOT_VERIFIED',
        );
    }

    public static function titleRequired(): self
    {
        return new self(
            'A track needs a title. The candidate has no suggestion and none was supplied — a '
                .'filename-derived guess is not always recoverable, and an untitled track is not '
                .'something a distributor will accept.',
            'TITLE_REQUIRED',
        );
    }

    public static function masterAlreadyInCatalogue(string $trackUuid): self
    {
        return new self(
            sprintf(
                'That master is already the master of track [%s]. Promoting it again would put the '
                    .'same recording in the catalogue twice, under two identifiers. Override '
                    .'explicitly if this is genuinely a second recording.',
                $trackUuid,
            ),
            'MASTER_ALREADY_IN_CATALOGUE',
        );
    }
}
