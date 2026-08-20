<?php

declare(strict_types=1);

namespace SaniTube\Media\Testing;

use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Exceptions\FingerprintFailed;
use SaniTube\Media\FingerprintResult;
use SaniTube\Media\Fingerprints\FingerprintPoints;

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
 * **It emits the same shape as the real tool** — a list of 32-bit words in
 * {@see FingerprintPoints} form — because the comparator measures agreement
 * bit by bit. A double that returned a hex digest would still be a string, and
 * every similarity measured against it would be a number computed over the
 * wrong thing. Two unrelated files land near 50% agreement here, which is
 * where two unrelated recordings land in reality.
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
            fingerprint: FingerprintPoints::encode(self::pointsFrom($contents)),
            durationSeconds: max(1, strlen($contents)),
            algorithm: $this->name().':'.$this->version(),
        );
    }

    /**
     * A fingerprint-shaped answer derived from the bytes.
     *
     * Long enough to clear the comparator's minimum overlap, so a test that
     * fingerprints two files gets a real measurement rather than a refusal to
     * measure.
     *
     * @return list<int>
     */
    public static function pointsFrom(string $contents, int $frames = 200): array
    {
        $seed = hash('sha256', $contents, true);
        $points = [];

        for ($frame = 0; $frame < $frames; $frame++) {
            /** @var array{1: int} $word */
            $word = unpack('N', substr(hash('sha256', $seed.$frame, true), 0, 4));
            $points[] = $word[1];
        }

        return $points;
    }
}
