<?php

declare(strict_types=1);

namespace SaniTube\Media\Contracts;

use SaniTube\Media\AudioAnalysisResult;

/**
 * Reads the technical facts out of an audio file.
 *
 * Technical, not musical. Duration, codec, sample rate and channel count are
 * properties of the file and two analysers should agree on them. Tempo, key,
 * genre and mood are interpretations, they belong to later tickets, and mixing
 * the two here would make "the analysis" a thing that can be wrong in two
 * completely different ways.
 *
 * {@see isAvailable()} exists because FFmpeg is a capability, not a
 * dependency. A server without it must still run: it registers assets, holds
 * the catalogue, and reports that it cannot analyse anything. An analyser that
 * threw on a missing binary would make that a crash instead of a fact.
 */
interface AudioAnalyzer
{
    /**
     * The name recorded against every analysis this produced.
     */
    public function name(): string;

    /**
     * Bumped whenever the meaning of the output changes.
     *
     * Stored with each analysis so a recording can be re-analysed later by a
     * better version without anyone having to guess which rows are stale.
     */
    public function version(): string;

    /**
     * Whether this server can actually run it right now.
     */
    public function isAvailable(): bool;

    /**
     * @param  string  $localPath  a readable file on this machine
     */
    public function analyze(string $localPath): AudioAnalysisResult;
}
