<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Enums;

use SaniTube\MusicGeneration\Contracts\DeclaresCapabilities;
use SaniTube\MusicGeneration\Contracts\MusicGenerationProvider;
use SaniTube\MusicGeneration\Contracts\SynchronousMusicGenerationProvider;
use SaniTube\MusicGeneration\Services\ProviderCapabilities;
use SaniTube\Observability\Capabilities;

/**
 * What a music generation provider can actually do.
 *
 * GEN-006 exists because the research that preceded it found exactly one
 * provider with a contract this environment could verify, and that contract
 * turned out to be **synchronous** — no job id, no polling, no webhook, no
 * cancel — while {@see MusicGenerationProvider} was written on the assumption
 * that generation is asynchronous. One verified provider was enough to show
 * that assuming a provider's shape is how an adapter layer acquires a silent
 * dependency on its first supplier. So the platform asks instead.
 *
 * Two rules keep this list honest.
 *
 * **A capability is what a provider can do, not whether it may do it now.**
 * Credentials, quota, circuit state and operator preference are separate
 * questions with separate answers, and {@see ProviderCapabilities} deliberately
 * knows none of them. A provider with an expired key is still capable of
 * TEXT_TO_MUSIC; it is merely unavailable. Conflating the two is what produces
 * an interface that shows a feature disappearing because a key lapsed.
 *
 * **A capability is declared or structurally implied, never guessed.** Two of
 * these — {@see self::TextToMusic} and {@see self::Cancel} — look like they
 * could be read off an interface, and only one of them can. Every provider is
 * asked for music against a prompt, so text-to-music is structural. Every
 * asynchronous provider also implements `cancelGeneration()`, but the contract
 * explicitly allows it to return false for ever, so a provider that merely has
 * the method has told us nothing. That one must be declared.
 *
 * {@see self::Synchronous} and {@see self::AsyncPolling} are the exception that
 * proves the rule: they are structural, but of a *sub-contract*. Implementing
 * {@see SynchronousMusicGenerationProvider}
 * is not an opinion about what a supplier can do — it is a method that exists
 * and can be called.
 *
 * Not an ADR-0016 truth level and not a {@see Capabilities}
 * detector. Those describe the environment SaniTube is running in; this
 * describes a supplier's product surface.
 */
enum GenerationCapability: string
{
    /** A prompt in, music out. Structural: it is what the base contract does. */
    case TextToMusic = 'TEXT_TO_MUSIC';

    /** Words the operator wrote are sung, rather than invented from a prompt. */
    case LyricsToMusic = 'LYRICS_TO_MUSIC';

    /** Can be asked for music with no vocal at all, and honours it. */
    case Instrumental = 'INSTRUMENTAL';

    /** Accepts an audio file as a style or melody reference. */
    case ReferenceAudio = 'REFERENCE_AUDIO';

    /** Transforms a supplied recording rather than starting from nothing. */
    case AudioToAudio = 'AUDIO_TO_AUDIO';

    /** Continues a finished generation past its end. */
    case Extend = 'EXTEND';

    /** Re-generates a finished work in a different direction, keeping its identity. */
    case Remix = 'REMIX';

    /** Returns separated parts — the thing that makes a track sync-licensable. */
    case StemSeparation = 'STEM_SEPARATION';

    /** Can describe or analyse audio it is given, rather than only producing it. */
    case AudioUnderstanding = 'AUDIO_UNDERSTANDING';

    /** Returns per-word or per-line timings alongside the audio. */
    case LyricsTimestamps = 'LYRICS_TIMESTAMPS';

    /**
     * Ask, and the answer is the audio.
     *
     * Structural for a {@see SynchronousMusicGenerationProvider}:
     * it is what implementing that interface means. A supplier may declare both
     * this and {@see self::AsyncPolling} if it genuinely offers both, which is
     * why they are separate capabilities rather than one either-or field.
     */
    case Synchronous = 'SYNCHRONOUS';

    /**
     * Ask, get a reference, come back for the result.
     *
     * Structural for a {@see MusicGenerationProvider}.
     */
    case AsyncPolling = 'ASYNC_POLLING';

    /**
     * Cancellation reaches the provider and stops work.
     *
     * Declared, never inferred. `cancelGeneration()` is on the base contract
     * and returning false is a documented answer, so the method's existence is
     * not evidence. A synchronous provider has nothing to cancel.
     */
    case Cancel = 'CANCEL';

    /** Calls back when a job finishes, so the platform need not poll. */
    case Webhook = 'WEBHOOK';

    /**
     * Runs on infrastructure the operator controls.
     *
     * Recorded because it changes what the other answers mean: a self-hosted
     * provider has no per-track cost and no third-party retention, and its
     * availability is the operator's problem rather than a supplier's SLA.
     */
    case SelfHosted = 'SELF_HOSTED';

    /**
     * The capabilities a provider has by virtue of implementing a contract at
     * all — the ones read off the interface rather than claimed.
     *
     * Only ever `TEXT_TO_MUSIC`, because that is the one thing every
     * sub-contract has in common: a prompt goes in and music comes out. The
     * *execution* capabilities are structural too, but of a particular
     * sub-contract rather than of providers in general, so
     * {@see ProviderCapabilities} derives
     * those from `instanceof` instead of from this list.
     *
     * @return list<self>
     */
    public static function structural(): array
    {
        return [self::TextToMusic];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $capability): string => $capability->value, self::cases());
    }

    /**
     * Parse a configured or stored capability name, tolerating case and
     * spacing but never inventing one.
     */
    public static function parse(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return self::tryFrom(strtoupper(trim($value)));
    }

    /**
     * Whether an adapter must say so itself, rather than the platform reading
     * it off the interface. See {@see DeclaresCapabilities}.
     */
    public function mustBeDeclared(): bool
    {
        return ! in_array($this, self::structural(), true);
    }
}
