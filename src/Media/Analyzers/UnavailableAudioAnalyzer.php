<?php

declare(strict_types=1);

namespace SaniTube\Media\Analyzers;

use SaniTube\Media\AudioAnalysisResult;
use SaniTube\Media\Contracts\AudioAnalyzer;

/**
 * The analyser a server without FFmpeg gets.
 *
 * It exists so that "this host cannot analyse audio" is an ordinary,
 * describable state rather than a null somewhere in the pipeline. An install
 * on shared hosting is not broken; it simply cannot answer questions about
 * waveforms yet, and everything else — ingestion, the catalogue, releases —
 * has to keep working.
 *
 * It never reports failure. Failure would mean the *file* was wrong, and
 * blaming a recording for the server's missing binary is how a perfectly good
 * master ends up quarantined.
 */
final readonly class UnavailableAudioAnalyzer implements AudioAnalyzer
{
    public function name(): string
    {
        return 'unavailable';
    }

    public function version(): string
    {
        return '1';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function analyze(string $localPath): AudioAnalysisResult
    {
        return AudioAnalysisResult::failed(
            'No audio analyser is available on this server. Install FFmpeg, or set SANITUBE_FFPROBE_PATH '
                .'to an uploaded static build; nothing about the file is in question.',
        );
    }
}
