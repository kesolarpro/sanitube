<?php

declare(strict_types=1);

namespace SaniTube\Localization;

/**
 * Decides which interface locale an incoming request should be served in.
 *
 * Precedence: explicit choice → stored preference → Accept-Language → default.
 * Every step is optional and unknown values are ignored rather than rejected,
 * so a stale cookie or an exotic browser header can never produce a 4xx.
 */
final readonly class LocaleNegotiator
{
    public function __construct(
        private LocaleRegistry $locales,
        private bool $useAcceptLanguage = true,
    ) {}

    public function negotiate(
        ?string $explicit = null,
        ?string $stored = null,
        ?string $acceptLanguage = null,
    ): UiLocale {
        return $this->locales->resolve($explicit)
            ?? $this->locales->resolve($stored)
            ?? ($this->useAcceptLanguage ? $this->fromAcceptLanguage($acceptLanguage) : null)
            ?? $this->locales->default();
    }

    /**
     * Parse an RFC 9110 Accept-Language header and return the best supported
     * match, honouring quality values and ignoring `q=0` entries.
     */
    public function fromAcceptLanguage(?string $header): ?UiLocale
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $header) as $position => $part) {
            $segments = explode(';', trim($part));
            $tag = trim($segments[0]);

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($segments, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $matches) === 1) {
                    $quality = (float) $matches[1];
                }
            }

            if ($quality <= 0.0) {
                continue;
            }

            // Preserve header order for equal quality values.
            $candidates[] = ['tag' => $tag, 'quality' => $quality, 'position' => $position];
        }

        usort(
            $candidates,
            static fn (array $a, array $b): int => $b['quality'] <=> $a['quality'] ?: $a['position'] <=> $b['position'],
        );

        foreach ($candidates as $candidate) {
            if ($locale = $this->locales->resolve($candidate['tag'])) {
                return $locale;
            }
        }

        return null;
    }
}
