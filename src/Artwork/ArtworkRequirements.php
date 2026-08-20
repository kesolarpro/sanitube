<?php

declare(strict_types=1);

namespace SaniTube\Artwork;

/**
 * The configured requirements, read once and answered as questions.
 *
 * A small object rather than `config()` calls scattered through the validator,
 * so that "no requirement" is expressed in one place. Zero and null both mean
 * *unset* here, and the validator never has to remember which a given setting
 * uses.
 *
 * **Nothing in this class or its configuration names a distributor.** Stores
 * disagree at the edges and an operator delivering somewhere with different
 * rules changes a number, not a validator.
 */
final readonly class ArtworkRequirements
{
    /**
     * @param  list<string>  $acceptedMimeTypes
     */
    private function __construct(
        public ?int $minimumPixels,
        public ?int $maximumPixels,
        public bool $mustBeSquare,
        public ?int $maximumBytes,
        public array $acceptedMimeTypes,
        public bool $refusesCmyk,
    ) {}

    public static function fromConfiguration(): self
    {
        return new self(
            minimumPixels: self::positive('minimum_pixels'),
            maximumPixels: self::positive('maximum_pixels'),
            mustBeSquare: (bool) config('artwork.requirements.must_be_square', true),
            maximumBytes: self::positive('maximum_bytes'),
            acceptedMimeTypes: self::mimeTypes(),
            refusesCmyk: (bool) config('artwork.requirements.refuse_cmyk', true),
        );
    }

    public function accepts(string $mimeType): bool
    {
        // An empty list is "any type", not "no type". The alternative would
        // make an operator who cleared the list unable to deliver anything,
        // which is never what clearing a list is meant to express.
        return $this->acceptedMimeTypes === []
            || in_array(strtolower($mimeType), $this->acceptedMimeTypes, true);
    }

    private static function positive(string $key): ?int
    {
        $value = (int) config('artwork.requirements.'.$key, 0);

        return $value > 0 ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function mimeTypes(): array
    {
        $configured = config('artwork.requirements.accepted_mime_types', []);

        if (! is_array($configured)) {
            return [];
        }

        $types = [];

        foreach ($configured as $type) {
            if (is_string($type) && trim($type) !== '') {
                $types[] = strtolower(trim($type));
            }
        }

        return array_values(array_unique($types));
    }
}
