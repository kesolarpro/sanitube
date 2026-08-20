<?php

declare(strict_types=1);

namespace SaniTube\Media\Fingerprinters;

use SaniTube\Media\Analyzers\ProbeRunner;
use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Exceptions\FingerprintFailed;
use SaniTube\Media\FingerprintResult;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Acoustic fingerprints via Chromaprint's `fpcalc`.
 *
 * **Chromaprint rather than something written here.** Acoustic fingerprinting
 * is signal processing with a decade of tuning behind it; a hand-rolled version
 * would be worse in ways that only show up as wrong answers on real audio,
 * which is the worst way for a comparison to be wrong. This platform's
 * contribution is deciding what to do with the evidence, not producing it.
 *
 * **Only part of the file is read.** `-length` bounds the decode to the opening
 * of the track. Fingerprinting a full album's worth of masters end to end is
 * minutes of CPU per file, and the opening two minutes identify a recording as
 * well as the whole of it — the tail is where fades and silence live, and they
 * are the least distinctive part.
 *
 * **The invocation is an argument list, never a shell string.** A filename is
 * caller-controlled, and `fpcalc "$path"` is a command injection waiting for
 * the first file called `; rm -rf`. {@see ProbeRunner} takes an array and
 * Symfony's Process never involves a shell.
 */
final readonly class ChromaprintFingerprinter implements AudioFingerprinter
{
    /**
     * Bumped when the *meaning* of the output changes.
     *
     * Fingerprints from different algorithm versions are not reliably
     * comparable, and this is what lets a recalibration tell which stored rows
     * it may compare and which it must recompute.
     */
    public const VERSION = '1';

    public function __construct(
        private ProbeRunner $runner = new ProbeRunner,
        private ExecutableFinder $finder = new ExecutableFinder,
    ) {}

    public function name(): string
    {
        return 'chromaprint';
    }

    public function version(): string
    {
        return self::VERSION;
    }

    public function isAvailable(): bool
    {
        return $this->binary() !== null;
    }

    public function fingerprint(string $localPath): FingerprintResult
    {
        $binary = $this->binary();

        if ($binary === null) {
            throw FingerprintFailed::toolUnavailable((string) config('media.fpcalc.path', 'fpcalc'));
        }

        try {
            $output = $this->runner->run(
                $binary,
                [
                    // JSON, so parsing is a decode rather than a guess at a
                    // key=value format that has changed before.
                    '-json',
                    '-length', (string) $this->seconds(),
                    $localPath,
                ],
                (int) config('media.fpcalc.timeout', 120),
            );
        } catch (Throwable $exception) {
            throw FingerprintFailed::toolFailed($this->name(), $exception->getMessage());
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded) || ! is_string($decoded['fingerprint'] ?? null)) {
            throw FingerprintFailed::unreadableOutput($this->name());
        }

        return new FingerprintResult(
            fingerprint: $decoded['fingerprint'],
            // The tool's own reading of what it decoded. Deliberately not the
            // analysis row's duration: the fingerprint is a function of the
            // audio fpcalc actually saw, and a disagreement between the two is
            // information a comparison needs.
            durationSeconds: (int) round((float) ($decoded['duration'] ?? 0)),
            algorithm: $this->name().':'.self::VERSION,
        );
    }

    /**
     * How much of the file to read.
     *
     * Clamped rather than trusted: a configured zero would fingerprint nothing
     * and a configured hour would make the nightly pass never finish.
     */
    private function seconds(): int
    {
        $configured = (int) config('media.fpcalc.length_seconds', 120);

        return max(30, min(600, $configured));
    }

    private function binary(): ?string
    {
        $configured = (string) config('media.fpcalc.path', 'fpcalc');

        // An absolute path is taken at its word; a bare name is looked up, so
        // a host that keeps it somewhere unusual works without configuration.
        if (str_contains($configured, DIRECTORY_SEPARATOR)) {
            return is_executable($configured) ? $configured : null;
        }

        return $this->finder->find($configured);
    }
}
