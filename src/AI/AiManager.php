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
    /**
     * What an absent or blank configuration means.
     *
     * Public because it is the *name*, not an implementation detail: callers
     * and tests both need to be able to say "the disabled one" without
     * spelling it, and the one thing this ticket learned is that spelling it
     * `null` breaks.
     */
    public const DISABLED = 'none';

    /** @var array<string, AiProvider> */
    private array $resolved = [];

    /**
     * @param  array<string, array<string, mixed>>  $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly string $defaultProvider,
    ) {}

    /**
     * The one place a configured provider name is interpreted.
     *
     * **Empty configuration means `none`. Unknown non-empty configuration is
     * an error.** Those two halves are deliberately asymmetric, and the
     * asymmetry is the whole point: a fresh install, an unset variable, a
     * blank line and the legacy value `null` all mean "no AI here", and every
     * one of them must leave the platform working. A typo like `openaai` means
     * somebody intended a provider and misspelt it, and silently disabling AI
     * would hide that until an audit asked which model wrote a description.
     *
     * Laravel converts the literal string `null` in a `.env` file to PHP null,
     * so the null case below is not defensive programming — it is the exact
     * value `SANITUBE_AI_PROVIDER=null` produces, and it is what turned every
     * AI test red in CI while passing locally.
     *
     * This is the documented configuration boundary. Nothing downstream
     * normalises anything: {@see provider()} refuses an empty name, because at
     * that point it is a caller's bug rather than an operator's blank field.
     */
    public static function normaliseProviderName(mixed $configured): string
    {
        if (! is_string($configured)) {
            return self::DISABLED;
        }

        $trimmed = trim($configured);

        // `null` and `none` both arrive here as strings when the value comes
        // from somewhere other than the environment — a config cache, a test.
        return $trimmed === '' || strtolower($trimmed) === 'null'
            ? self::DISABLED
            : $trimmed;
    }

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
        // Deliberately not normalised here. Normalisation happens once, at the
        // configuration boundary; an empty name reaching this point means a
        // caller passed one, and answering it with the disabled provider would
        // turn a bug into a silently AI-less installation.
        $configuration = $name === '' ? null : ($this->providers[$name] ?? null);

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
