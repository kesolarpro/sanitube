<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Exceptions;

use RuntimeException;

/**
 * Why a frontend build was not installed.
 *
 * Refusals here guard two different things: the *bundle* (a build with no
 * manifest is not a build) and the *machine* (an archive entry that walks out
 * of its directory is an attack, and its path is not echoed back).
 */
final class FrontendBuildException extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function sourceMissing(string $path): self
    {
        return new self(sprintf('[%s] does not exist.', $path), 'BUILD_SOURCE_MISSING');
    }

    public static function noManifest(): self
    {
        return new self(
            'The source carries no readable manifest.json, so it is not a Vite build. An artifact that '
                .'lost its manifest renders nothing — refusing it here is the same check CI runs before '
                .'publishing.',
            'BUILD_NO_MANIFEST',
        );
    }

    public static function emptyManifest(): self
    {
        return new self(
            'The manifest parses but names no entries. A bundle that builds nothing is not a bundle.',
            'BUILD_EMPTY_MANIFEST',
        );
    }

    public static function unsafeArchiveEntry(): self
    {
        // Deliberately pathless: the entry name is the payload.
        return new self(
            'The archive contains an entry that would land outside the build directory. That is not a '
                .'damaged artifact, it is a hostile one; nothing from it was installed.',
            'BUILD_UNSAFE_ARCHIVE',
        );
    }

    public static function commitMismatch(string $expected, string $actual): self
    {
        return new self(
            sprintf(
                'This build was produced from %s but the application is at %s. A bundle from one commit '
                    .'served by another mixes hashed filenames until pages load assets that do not exist. '
                    .'Deploy the matching code first, or fetch the artifact for this commit.',
                $expected,
                $actual,
            ),
            'BUILD_COMMIT_MISMATCH',
        );
    }

    public static function swapFailed(string $detail): self
    {
        return new self('The build could not be swapped in: '.$detail, 'BUILD_SWAP_FAILED');
    }
}
