<?php

declare(strict_types=1);

namespace SaniTube\AI;

use SaniTube\AI\Contracts\AiProvider;
use SaniTube\AI\Exceptions\UnknownAiProvider;
use SaniTube\AI\Providers\ClaudeProvider;
use SaniTube\AI\Providers\NullAiProvider;
use SaniTube\AI\Providers\OpenAiProvider;
use SaniTube\Storage\StorageManager;

/**
 * Resolves configured AI providers by name.
 *
 * The same shape as {@see StorageManager}, deliberately: a
 * vendor is a value in configuration, and swapping one is a deployment change
 * rather than a code change. Nothing in the domain names OpenAI or Claude.
 *
 * Configuration is read once, at construction — see the storage manager for
 * why. Registering a provider at runtime works at any time and is how tests
 * substitute a fake without touching the network.
 */
final class AiManager
{
    /** @var array<string, AiProvider> */
    private array $resolved = [];

    /**
     * @param  array<string, array<string, mixed>>  $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly string $defaultProvider,
    ) {}

    public function default(): AiProvider
    {
        return $this->provider($this->defaultProvider);
    }

    public function defaultName(): string
    {
        return $this->defaultProvider;
    }

    public function provider(string $name): AiProvider
    {
        return $this->resolved[$name] ??= $this->resolve($name);
    }

    /**
     * Substitute a provider by name. The seam a test uses instead of the
     * network, and the seam a future ticket uses to route one purpose to a
     * different vendor.
     */
    public function register(string $name, AiProvider $provider): void
    {
        $this->resolved[$name] = $provider;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique([
            ...array_keys($this->providers),
            ...array_keys($this->resolved),
        ]));
    }

    /**
     * Whether this installation can call a model at all.
     *
     * Asked before an AI-assisted feature is offered, so that a user is never
     * shown a button that reports a missing key after they press it.
     */
    public function isAvailable(): bool
    {
        return $this->default()->isAvailable();
    }

    private function resolve(string $name): AiProvider
    {
        $configuration = $this->providers[$name] ?? null;

        if (! is_array($configuration)) {
            throw UnknownAiProvider::named($name, array_keys($this->providers));
        }

        $driver = is_string($configuration['driver'] ?? null) ? $configuration['driver'] : $name;

        return match ($driver) {
            'openai' => new OpenAiProvider($name, $configuration),
            'claude' => new ClaudeProvider($name, $configuration),
            'null' => new NullAiProvider,
            default => throw UnknownAiProvider::named($driver, ['openai', 'claude', 'null']),
        };
    }
}
