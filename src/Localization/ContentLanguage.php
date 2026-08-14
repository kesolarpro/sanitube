<?php

declare(strict_types=1);

namespace SaniTube\Localization;

use SaniTube\Localization\Exceptions\InvalidContentLanguageException;

/**
 * The language of a *work*: a track, a release title, a set of lyrics.
 *
 * This is never the same thing as the locale the dashboard is displayed in. A
 * French-speaking user routinely catalogues Spanish tracks; distributors and
 * DSPs care about the latter and ignore the former.
 *
 * The three states the platform must model beyond an ordinary language all
 * have standard ISO 639-2 codes, so no invented values are needed:
 *
 *   zxx — no linguistic content (instrumental)
 *   und — undetermined (unknown)
 *   mul — multiple languages
 */
final readonly class ContentLanguage
{
    /** No linguistic content: an instrumental. */
    public const INSTRUMENTAL = 'zxx';

    /** Undetermined: not yet classified. */
    public const UNKNOWN = 'und';

    /** Multiple languages in a single work. */
    public const MULTIPLE = 'mul';

    private const SPECIAL = [self::INSTRUMENTAL, self::UNKNOWN, self::MULTIPLE];

    private function __construct(public string $code) {}

    /**
     * Accepts an ISO 639-1 two-letter code or one of the special ISO 639-2
     * codes above. Regional variants are reduced to the primary subtag:
     * `pt-BR` becomes `pt`, because a recording has a language, not a market.
     */
    public static function fromCode(string $code): self
    {
        $normalised = self::normalise($code)
            ?? throw InvalidContentLanguageException::for($code);

        return new self($normalised);
    }

    public static function tryFromCode(?string $code): ?self
    {
        if ($code === null) {
            return null;
        }

        $normalised = self::normalise($code);

        return $normalised === null ? null : new self($normalised);
    }

    public static function instrumental(): self
    {
        return new self(self::INSTRUMENTAL);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }

    public static function multiple(): self
    {
        return new self(self::MULTIPLE);
    }

    public function isInstrumental(): bool
    {
        return $this->code === self::INSTRUMENTAL;
    }

    public function isUnknown(): bool
    {
        return $this->code === self::UNKNOWN;
    }

    public function isMultiple(): bool
    {
        return $this->code === self::MULTIPLE;
    }

    /**
     * True when the code carries workflow meaning rather than an actual
     * language — useful to decide whether lyrics are expected.
     */
    public function isSpecial(): bool
    {
        return in_array($this->code, self::SPECIAL, true);
    }

    /**
     * Whether a work in this language can meaningfully carry lyrics.
     */
    public function allowsLyrics(): bool
    {
        return ! $this->isInstrumental();
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }

    private static function normalise(string $code): ?string
    {
        $primary = strtolower(trim(preg_split('/[-_]/', trim($code))[0] ?? ''));

        if (in_array($primary, self::SPECIAL, true)) {
            return $primary;
        }

        return preg_match('/^[a-z]{2}$/', $primary) === 1 ? $primary : null;
    }
}
