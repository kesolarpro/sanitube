<?php

declare(strict_types=1);

namespace SaniTube\Ui\Settings;

use Illuminate\Contracts\Config\Repository;

/**
 * Which providers an operator may actually choose, per family.
 *
 * CFG-002. **One list, read by everything that has an opinion about it.** The
 * settings screen offers a section's `options`, the writer constrains the same
 * variable with `in:`, and the manager resolves whatever ends up in `.env`. Two
 * of those three were already reading `config`, separately, and the third — the
 * writer — had no opinion at all: `SANITUBE_GENERATION_PROVIDER`,
 * `SANITUBE_AI_PROVIDER` and `SANITUBE_DISTRIBUTOR` were published on the
 * screen and writable nowhere, so the screen listed choices nobody could make.
 *
 * The names come from the *declaration*, never from the driver `match` inside a
 * manager. That distinction is what CFG-002 was for: `acestep` had a resolution
 * arm and no declaration, so the only real generation provider this platform
 * has could not be selected — an operator setting it got
 * `UnknownGenerationProvider` and a section reporting `known: false`, with
 * nothing on any screen to explain why.
 *
 * Nothing here resolves a provider. Building one can open a client, and a
 * settings screen must render on an installation whose provider is misconfigured
 * — that is the screen somebody opens *because* it is.
 */
final readonly class SelectableProviders
{
    /**
     * Config keys, in the order they are declared, for each family.
     */
    private const FAMILIES = [
        'ai' => 'ai.providers',
        'generation' => 'generation.providers',
        'distribution' => 'distribution.distributors',
    ];

    public function __construct(private Repository $config) {}

    /**
     * Every provider name this build has a declaration for.
     *
     * @return list<string>
     */
    public function names(string $family): array
    {
        $path = self::FAMILIES[$family] ?? null;

        if ($path === null) {
            return [];
        }

        /** @var array<array-key, mixed> $declared */
        $declared = (array) $this->config->get($path, []);

        return array_values(array_filter(array_keys($declared), 'is_string'));
    }

    /**
     * Whether the configured name is one this build can resolve.
     */
    public function isKnown(string $family, string $selected): bool
    {
        return in_array($selected, $this->names($family), true);
    }

    /**
     * The `in:` rule for a family, derived rather than retyped.
     *
     * A free-text provider name is an installation that resolves to nothing on
     * the next request — and the screen that accepted it would have shown the
     * correct list of choices while doing so.
     */
    public function rule(string $family): string
    {
        return 'in:'.implode(',', $this->names($family));
    }
}
