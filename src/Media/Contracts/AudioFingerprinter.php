<?php

declare(strict_types=1);

namespace SaniTube\Media\Contracts;

use SaniTube\Media\Exceptions\FingerprintFailed;
use SaniTube\Media\FingerprintResult;

/**
 * Something that can say what an audio file sounds like, compactly.
 *
 * The same shape as {@see AudioAnalyzer}, and for the same reason: this is a
 * **capability**, not a dependency. An installation without the tool installed
 * still runs — it simply cannot tell that two differently-encoded files are
 * the same recording, which is a feature it lacks rather than a failure it
 * suffers.
 *
 * Implementations measure and return. They do not decide whether two
 * recordings are the same, whether a duplicate should be trashed, or what a
 * threshold is. Those are the platform's decisions and they are made where the
 * evidence is read, not where it is produced.
 */
interface AudioFingerprinter
{
    /**
     * The tool, recorded on every row it produces.
     */
    public function name(): string;

    /**
     * The tool's version, recorded beside the fingerprint.
     *
     * Fingerprints from different versions of an algorithm are not always
     * comparable. Storing the version is what lets a future recalibration know
     * which rows it may compare and which it must recompute.
     */
    public function version(): string;

    public function isAvailable(): bool;

    /**
     * @throws FingerprintFailed
     */
    public function fingerprint(string $localPath): FingerprintResult;
}
