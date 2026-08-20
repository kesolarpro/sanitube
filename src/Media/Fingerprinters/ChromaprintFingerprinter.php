<?php

declare(strict_types=1);

namespace SaniTube\Media\Fingerprinters;

use SaniTube\Media\Analyzers\ProbeRunner;
use SaniTube\Media\Contracts\AudioFingerprinter;
use SaniTube\Media\Exceptions\FingerprintFailed;
use SaniTube\Media\FingerprintResult;
use SaniTube\Media\Fingerprints\FingerprintPoints;
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
 * **The raw fingerprint is what gets stored, not the base64 one.** `fpcalc`
 * prints a compressed string by default and it is the form AcoustID's API
 * accepts, but it is entropy-coded: fingerprints differing by one bit compress
 * to strings differing everywhere, so it supports equality and nothing else.
 * Equality was never the interesting question — a WAV and an MP3 of one master
 * are the same recording and never byte-identical. `-raw` gives the 32-bit
 * words that {@see FingerprintPoints} stores and similarity is measured over.
 * Nothing here submits to AcoustID; if something ever does, that ticket adds
 * the compressed form beside this one rather than trading it away.
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
    public const VERSION = '2';

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
                    // The 32-bit words rather than the compressed string, so
                    // the result can be compared and not only matched.
                    '-raw',
                    '-length', (string) $this->seconds(),
                    $localPath,
                ],
                (int) config('media.fpcalc.timeout', 120),
            );
        } catch (Throwable $exception) {
            throw FingerprintFailed::toolFailed($this->name(), $exception->getMessage());
        }

        $decoded = json_decode($output, true);

        // `-raw` makes the fingerprint a list of integers. A string here means
        // the flag was dropped or the tool changed shape, and storing the
        // compressed form under a version that promises raw words would make
        // every later comparison quietly meaningless.
        if (! is_array($decoded) || ! is_array($decoded['fingerprint'] ?? null)) {
            throw FingerprintFailed::unreadableOutput($this->name());
        }

        $points = array_values(array_map(intval(...), array_filter($decoded['fingerprint'], is_numeric(...))));

        if ($points === []) {
            throw FingerprintFailed::unreadableOutput($this->name());
        }

        return new FingerprintResult(
            fingerprint: FingerprintPoints::encode($points),
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
