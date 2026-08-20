<?php

declare(strict_types=1);

namespace SaniTube\Media\Fingerprinters;

use SaniTube\Media\Analyzers\UnavailableAudioAnalyzer;
use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Exceptions\FingerprintFailed;
use SaniTube\Media\FingerprintResult;

/**
 * The fingerprinter an installation without the tool gets.
 *
 * It says so rather than throwing at boot. A shared cPanel account will not
 * have Chromaprint installed and should not fail to start because of it — the
 * library simply cannot be compared acoustically until somebody configures a
 * worker that can.
 *
 * The same shape as {@see UnavailableAudioAnalyzer},
 * because the decision is the same one: a missing capability is a fact to
 * report, not an error to raise.
 */
final readonly class UnavailableAudioFingerprinter implements AudioFingerprinter
{
    public function name(): string
    {
        return 'none';
    }

    public function version(): string
    {
        return '0';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function fingerprint(string $localPath): FingerprintResult
    {
        throw FingerprintFailed::toolUnavailable('fingerprinter');
    }
}
