<?php

declare(strict_types=1);

namespace SaniTube\Localization;

use SaniTube\Localization\Exceptions\UnsupportedLocaleException;

/**
 * A locale the *interface* can be rendered in.
 *
 * Deliberately not an enum: locales are data, and the platform must be able to
 * gain Arabic, Japanese, Korean, Hindi or Chinese by adding a config entry and
 * a translation directory, without touching a single class.
 */
final readonly class UiLocale
{
    public const DIRECTION_LTR = 'ltr';

    public const DIRECTION_RTL = 'rtl';

    public function __construct(
        public string $code,
        public string $name,
        public string $nativeName,
        public string $direction = self::DIRECTION_LTR,
    ) {}

    /**
     * @param  array{name?: string, native?: string, direction?: string}  $attributes
     */
    public static function fromArray(string $code, array $attributes): self
    {
        $normalised = self::normalise($code)
            ?? throw UnsupportedLocaleException::malformed($code);

        return new self(
            code: $normalised,
            name: $attributes['name'] ?? $code,
            nativeName: $attributes['native'] ?? $attributes['name'] ?? $code,
            direction: ($attributes['direction'] ?? self::DIRECTION_LTR) === self::DIRECTION_RTL
                ? self::DIRECTION_RTL
                : self::DIRECTION_LTR,
        );
    }

    /**
     * Reduce any BCP-47-ish tag to the primary language subtag: `fr_CA`,
     * `fr-CA` and `FR` all resolve to `fr`.
     *
     * Returns null when the value cannot be a language tag at all.
     */
    public static function normalise(string $candidate): ?string
    {
        $primary = preg_split('/[-_]/', trim($candidate))[0] ?? '';
        $primary = strtolower($primary);

        return preg_match('/^[a-z]{2,3}$/', $primary) === 1 ? $primary : null;
    }

    public function isRightToLeft(): bool
    {
        return $this->direction === self::DIRECTION_RTL;
    }

    /**
     * @return array{code: string, name: string, native_name: string, direction: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'native_name' => $this->nativeName,
            'direction' => $this->direction,
        ];
    }
}
