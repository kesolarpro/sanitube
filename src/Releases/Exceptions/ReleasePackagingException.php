<?php

declare(strict_types=1);

namespace SaniTube\Releases\Exceptions;

use RuntimeException;
use SaniTube\Foundation\Contracts\CarriesRefusalCode;

/**
 * A release this platform will not package for a distributor.
 *
 * Every case carries a machine-readable `reason`, per REL-002: a caller that
 * has to match on a sentence works around the check rather than fixing it.
 */
final class ReleasePackagingException extends RuntimeException implements CarriesRefusalCode
{
    /**
     * The reason, under the name every refusal in the platform answers to.
     *
     * `reason` stays because dozens of call sites read it. This is the same
     * value, reachable without knowing which module refused — which is what
     * lets one `catch` handle a packaging refusal and a distribution refusal
     * alike, instead of a caller listing the modules it happens to know about.
     */
    public function refusalCode(): string
    {
        return $this->reason;
    }

    /**
     * @param  list<string>  $context
     */
    private function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $codes  the validation codes that failed
     */
    public static function notValid(array $codes): self
    {
        return new self(
            'The release does not pass validation, so there is nothing safe to package. Packaging '
                .'an invalid release only moves the rejection to the distributor.',
            'RELEASE_NOT_VALID',
            $codes,
        );
    }

    public static function noCover(): self
    {
        return new self(
            'The release has no cover. Every store requires artwork, and a package without it is a '
                .'package that will be refused.',
            'NO_COVER',
        );
    }

    public static function noTracks(): self
    {
        return new self('The release has no tracks.', 'NO_TRACKS');
    }

    /**
     * @param  list<string>  $uuids
     */
    public static function trackWithoutMaster(array $uuids): self
    {
        return new self(
            'A track on this release has no master audio. There is nothing to deliver for it, and a '
                .'package that silently omitted it would deliver a release missing a track.',
            'TRACK_WITHOUT_MASTER',
            $uuids,
        );
    }
}
