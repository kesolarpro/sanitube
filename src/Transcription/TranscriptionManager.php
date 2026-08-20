<?php

declare(strict_types=1);

namespace SaniTube\Transcription;

use SaniTube\AI\AiManager;
use SaniTube\Transcription\Contracts\TranscriptionProvider;
use SaniTube\Transcription\Exceptions\UnknownTranscriptionProvider;
use SaniTube\Transcription\Providers\NullTranscriptionProvider;
use SaniTube\Transcription\Providers\OpenAiTranscriptionProvider;

/**
 * Resolves configured transcription providers by name.
 *
 * Deliberately the same shape as {@see AiManager} and the storage manager: a
 * supplier is a value in configuration, and swapping one is a deployment change
 * rather than a code change.
 *
 * The asymmetry in {@see normaliseProviderName()} is copied on purpose and for
 * the reason AI-001 learned the hard way. **Empty configuration means `none`;
 * unknown non-empty configuration is an error.** A fresh install, an unset
 * variable, a blank line and the literal `null` all mean "no transcription
 * here" and must leave the platform working. A typo like `whispr` means
 * somebody intended a supplier and misspelt it, and silently disabling would
 * hide that until an operator asked why nothing had a transcript.
 *
 * Laravel converts the literal string `null` in a `.env` file to PHP null, so
 * the null branch is not defensive programming — it is exactly what
 * `SANITUBE_TRANSCRIPTION_PROVIDER=null` produces.
 */
final class TranscriptionManager
{
    public const DISABLED = 'none';

    /** @var array<string, TranscriptionProvider> */
    private array $resolved = [];

    /**
     * @param  array<string, array<string, mixed>>  $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly string $defaultProvider,
    ) {}

    public static function normaliseProviderName(mixed $configured): string
    {
        if (! is_string($configured)) {
            return self::DISABLED;
        }

        $trimmed = trim($configured);

        return $trimmed === '' || strtolower($trimmed) === 'null'
            ? self::DISABLED
            : $trimmed;
    }

    public function default(): TranscriptionProvider
    {
        return $this->provider($this->defaultProvider);
    }

    public function defaultName(): string
    {
        return $this->defaultProvider;
    }

    public function provider(string $name): TranscriptionProvider
    {
        return $this->resolved[$name] ??= $this->resolve($name);
    }

    /**
     * Substitute a provider by name — the seam a test uses instead of the
     * network.
     */
    public function register(string $name, TranscriptionProvider $provider): void
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
     * Whether this installation can transcribe at all.
     *
     * Asked before the feature is offered, so nobody is shown a button that
     * reports a missing key after they press it.
     */
    public function isAvailable(): bool
    {
        return $this->default()->isAvailable();
    }

    private function resolve(string $name): TranscriptionProvider
    {
        // Not normalised here. Normalisation happens once, at the
        // configuration boundary; an empty name reaching this point means a
        // caller passed one, and answering it with the disabled provider would
        // turn a bug into a silently transcription-less installation.
        if ($name === '') {
            throw UnknownTranscriptionProvider::blank();
        }

        if ($name === self::DISABLED) {
            return new NullTranscriptionProvider;
        }

        $configuration = $this->providers[$name] ?? null;

        // A name that is not declared in configuration is unknown, and saying
        // so is the point: falling back to the disabled provider would leave
        // an operator who configured a supplier wondering for a week why
        // nothing was ever transcribed.
        if (! is_array($configuration)) {
            throw UnknownTranscriptionProvider::named($name);
        }

        // The name is the driver unless the installation says otherwise, so a
        // second endpoint for the same vendor is a configuration entry rather
        // than a class.
        $driver = is_string($configuration['driver'] ?? null) ? $configuration['driver'] : $name;

        return match ($driver) {
            'openai' => new OpenAiTranscriptionProvider($configuration),
            self::DISABLED => new NullTranscriptionProvider,
            default => throw UnknownTranscriptionProvider::named($driver),
        };
    }
}
