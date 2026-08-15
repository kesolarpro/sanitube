<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration;

use SaniTube\AI\AiManager;
use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\Providers\FakeMusicGenerationProvider;
use SaniTube\MusicGeneration\Providers\UnavailableMusicGenerationProvider;

/**
 * Resolves configured generation providers by name.
 *
 * The same shape as the storage and AI managers, and disabled by the same
 * convention: the provider that does nothing is called `none`, never `null`.
 * AI-001 learned the hard way that Laravel reads the literal string `null` in
 * a `.env` file as PHP null, so `null` as a configuration value means "no
 * value" rather than "the provider named null".
 *
 * Configuration is read once, at construction. Registering a provider at
 * runtime works at any time and is how tests substitute the fake.
 */
final class MusicGenerationManager
{
    public const DISABLED = 'none';

    /** @var array<string, MusicGenerationProvider> */
    private array $resolved = [];

    /**
     * @param  array<string, array<string, mixed>>  $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly string $defaultProvider,
    ) {}

    /**
     * Empty configuration means `none`; an unknown non-empty name is an error.
     * See {@see AiManager::normaliseProviderName()} — same rule,
     * same reasoning, and deliberately the same behaviour so an operator does
     * not have to learn two.
     */
    public static function normaliseProviderName(mixed $configured): string
    {
        if (! is_string($configured)) {
            return self::DISABLED;
        }

        $trimmed = trim($configured);

        return $trimmed === '' || strtolower($trimmed) === 'null' ? self::DISABLED : $trimmed;
    }

    public function default(): MusicGenerationProvider
    {
        return $this->provider($this->defaultProvider);
    }

    public function defaultName(): string
    {
        return $this->defaultProvider;
    }

    public function provider(string $name): MusicGenerationProvider
    {
        return $this->resolved[$name] ??= $this->resolve($name);
    }

    public function register(string $name, MusicGenerationProvider $provider): void
    {
        $this->resolved[$name] = $provider;
    }

    public function isAvailable(): bool
    {
        return $this->default()->isAvailable();
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique([...array_keys($this->providers), ...array_keys($this->resolved)]));
    }

    private function resolve(string $name): MusicGenerationProvider
    {
        $configuration = $name === '' ? null : ($this->providers[$name] ?? null);

        if (! is_array($configuration)) {
            throw Exceptions\UnknownGenerationProvider::named($name, array_keys($this->providers));
        }

        $driver = is_string($configuration['driver'] ?? null) ? $configuration['driver'] : $name;

        return match ($driver) {
            'fake' => new FakeMusicGenerationProvider,
            'none' => new UnavailableMusicGenerationProvider,
            default => throw Exceptions\UnknownGenerationProvider::named($driver, ['fake', 'none']),
        };
    }
}
