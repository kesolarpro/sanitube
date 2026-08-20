<?php

declare(strict_types=1);

namespace SaniTube\Media\Testing;

use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Exceptions\FingerprintFailed;
use SaniTube\Media\FingerprintResult;

/**
 * A fingerprinter that does not need Chromaprint installed.
 *
 * It derives its answer from the file's own bytes, so identical files
 * fingerprint identically and different files do not — which is the only
 * property the platform's own logic depends on. It is deliberately **not** an
 * acoustic model: it cannot tell that a WAV and an MP3 of the same recording
 * match, and pretending otherwise would let tests assert behaviour the real
 * tool provides and this one does not.
 *
 * Tests that need "same recording, different encoding" set the fingerprint
 * explicitly with {@see self::willReturn()}. That keeps the fixture honest
 * about which part is being exercised.
 */
final class FakeAudioFingerprinter implements AudioFingerprinter
{
    private ?FingerprintResult $forced = null;

    private bool $available = true;

    private bool $fails = false;

    public function willReturn(string $fingerprint, int $durationSeconds): self
    {
        $this->forced = new FingerprintResult($fingerprint, $durationSeconds, $this->name().':'.$this->version());

        return $this;
    }

    public function unavailable(): self
    {
        $this->available = false;

        return $this;
    }

    public function failing(): self
    {
        $this->fails = true;

        return $this;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function version(): string
    {
        return '1';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function fingerprint(string $localPath): FingerprintResult
    {
        if ($this->fails) {
            throw FingerprintFailed::toolFailed($this->name(), 'the fake was told to fail');
        }

        if ($this->forced instanceof FingerprintResult) {
            return $this->forced;
        }

        $contents = (string) @file_get_contents($localPath);

        return new FingerprintResult(
            fingerprint: hash('sha256', $contents),
            durationSeconds: max(1, strlen($contents)),
            algorithm: $this->name().':'.$this->version(),
        );
    }
}
