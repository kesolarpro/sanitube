<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Providers;

use SaniTube\Transcription\Contracts\TranscriptionProvider;
use SaniTube\Transcription\Exceptions\TranscriptionFailed;
use SaniTube\Transcription\TranscriptionResult;

/**
 * No transcription supplier at all.
 *
 * **The default, and a legitimate permanent choice.** An installation that
 * never configures one is not degraded: it ingests, deduplicates, organises and
 * distributes exactly as it would otherwise, and simply has no transcripts.
 *
 * It refuses rather than returning an empty transcript. An empty string is a
 * *result* — the model heard nothing — and storing one here would put a row in
 * the table saying this asset has no speech in it, which nobody established.
 */
final readonly class NullTranscriptionProvider implements TranscriptionProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function version(): string
    {
        return '1';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function transcribe(string $localPath, ?string $languageHint = null): TranscriptionResult
    {
        throw TranscriptionFailed::providerUnavailable($this->name());
    }
}
