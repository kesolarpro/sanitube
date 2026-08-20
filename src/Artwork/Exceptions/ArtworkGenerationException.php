<?php

declare(strict_types=1);

namespace SaniTube\Artwork\Exceptions;

use RuntimeException;
use SaniTube\Artwork\Enums\ArtworkRefusal;

/**
 * Artwork this platform will not generate, with a machine-readable reason.
 */
final class ArtworkGenerationException extends RuntimeException
{
    /**
     * @param  array<string, int|string|null>  $context
     */
    private function __construct(
        string $message,
        public readonly ArtworkRefusal $refusal,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function noProvider(): self
    {
        return new self(
            'No image provider is configured on this installation. Artwork is attached by hand, '
                .'exactly as before.',
            ArtworkRefusal::NoProvider,
        );
    }

    /**
     * @param  array<string, int|string|null>  $context
     */
    public static function requirementsUnreachable(array $context): self
    {
        return new self(
            'The configured image provider cannot produce artwork this installation would accept. '
                .'Nothing was sent, so nothing was spent: lower the requirement or configure a '
                .'provider that can reach it.',
            ArtworkRefusal::RequirementsUnreachable,
            $context,
        );
    }

    public static function providerFailed(string $provider): self
    {
        return new self(
            sprintf('The [%s] image provider produced nothing usable.', $provider),
            ArtworkRefusal::ProviderFailed,
            ['provider' => $provider],
        );
    }

    public static function notStored(): self
    {
        return new self(
            'The image was generated but could not be stored, so no asset was registered.',
            ArtworkRefusal::NotStored,
        );
    }

    public static function emptyPrompt(): self
    {
        return new self(
            'An image needs something to describe it.',
            ArtworkRefusal::EmptyPrompt,
        );
    }
}
