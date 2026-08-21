<?php

declare(strict_types=1);

namespace SaniTube\Worker\Enums;

use SaniTube\Worker\WorkerRegistry;

/**
 * A kind of work a SaniTube worker can be asked to do.
 *
 * **The vocabulary, not a list of what is built.** GEN-008 created a remote
 * boundary for one purpose — running ACE-Step on a host with a GPU — and the
 * temptation afterwards is to create a second one for FFmpeg, a third for
 * Chromaprint, a fourth for transcription. Four boundaries means four
 * authentication schemes, four failure vocabularies and four things to deploy.
 *
 * So there is one boundary and it is capability-addressed: a worker declares
 * what it can do, Core asks for a capability by name, and adding a fifth needs
 * no change to the protocol, the authentication, or Core's client.
 *
 * **Most of these have no implementation, deliberately.** A capability named
 * here is a word the protocol already has; it is not a promise that any worker
 * answers to it. {@see WorkerRegistry} reports only what is
 * actually registered and actually runnable, and Core asks the worker rather
 * than assuming.
 */
enum WorkerCapability: string
{
    /**
     * Read a file's technical facts — duration, sample rate, channels.
     *
     * Today `ffprobe`, run locally by `SaniTube\Media\Analyzers\FfprobeAudioAnalyzer`.
     * Named in prose rather than imported: this module describes *kinds of
     * work*, and importing the module that does one would be the first place
     * the boundary stopped being general.
     * Named here because shared hosting frequently has no FFmpeg, and a worker
     * that does is the honest answer for those installations.
     */
    case MediaProbe = 'MEDIA_PROBE';

    /** An acoustic fingerprint. Today `fpcalc`/Chromaprint, run locally. */
    case AudioFingerprint = 'AUDIO_FINGERPRINT';

    /** Integrated loudness and true peak — what a master is measured against. */
    case LoudnessAnalysis = 'LOUDNESS_ANALYSIS';

    /** A waveform summary for display. */
    case Waveform = 'WAVEFORM';

    /** Transcodes and clips made from a master. */
    case DerivativeAudio = 'DERIVATIVE_AUDIO';

    /** Speech to text with timings. Today a hosted provider, called from Core. */
    case Transcription = 'TRANSCRIPTION';

    /**
     * Make music. The first capability with a handler, and the reason the
     * boundary exists: an engine that needs a GPU cannot run where Core does.
     */
    case MusicGeneration = 'MUSIC_GENERATION';

    /** Separate a finished recording into parts. */
    case StemSeparation = 'STEM_SEPARATION';

    /** Describe or analyse audio rather than produce it. */
    case AudioUnderstanding = 'AUDIO_UNDERSTANDING';

    /**
     * The URL segment this capability is addressed by.
     *
     * Lower-case and hyphenated, because it appears in a path. Derived rather
     * than a second constant: two spellings of one capability is how a route
     * and a registry stop agreeing.
     */
    public function slug(): string
    {
        return str_replace('_', '-', strtolower($this->value));
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $capability) {
            if ($capability->slug() === strtolower(trim($slug))) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $capability): string => $capability->value, self::cases());
    }
}
