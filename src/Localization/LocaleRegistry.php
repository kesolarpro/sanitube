<?php

declare(strict_types=1);

namespace SaniTube\Localization;

use SaniTube\Localization\Exceptions\UnsupportedLocaleException;

/**
 * The set of locales the interface can be served in, built from
 * `config/localization.php`.
 */
final class LocaleRegistry
{
    /** @var array<string, UiLocale> */
    private array $supported = [];

    /**
     * @param  array<string, array{name?: string, native?: string, direction?: string}>  $supported
     */
    public function __construct(
        array $supported,
        private readonly string $configuredDefault = 'en',
        private readonly string $configuredFallback = 'en',
    ) {
        foreach ($supported as $code => $attributes) {
            $locale = UiLocale::fromArray((string) $code, $attributes);
            $this->supported[$locale->code] = $locale;
        }

        if ($this->supported === []) {
            throw new UnsupportedLocaleException('At least one supported locale must be configured.');
        }
    }

    /**
     * @return array<string, UiLocale>
     */
    public function supported(): array
    {
        return $this->supported;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->supported);
    }

    public function isSupported(string $candidate): bool
    {
        $code = UiLocale::normalise($candidate);

        return $code !== null && isset($this->supported[$code]);
    }

    public function get(string $candidate): UiLocale
    {
        return $this->resolve($candidate)
            ?? throw UnsupportedLocaleException::notSupported($candidate, $this->codes());
    }

    /**
     * Best-effort resolution: returns null instead of throwing so callers can
     * fall through to the next negotiation step.
     */
    public function resolve(?string $candidate): ?UiLocale
    {
        if ($candidate === null || $candidate === '') {
            return null;
        }

        $code = UiLocale::normalise($candidate);

        return $code === null ? null : ($this->supported[$code] ?? null);
    }

    /**
     * Never throws: a misconfigured default must not take the application down,
     * it degrades to the first supported locale.
     */
    public function default(): UiLocale
    {
        return $this->resolve($this->configuredDefault)
            ?? $this->fallback();
    }

    public function fallback(): UiLocale
    {
        return $this->resolve($this->configuredFallback)
            ?? $this->supported[array_key_first($this->supported)];
    }
}
