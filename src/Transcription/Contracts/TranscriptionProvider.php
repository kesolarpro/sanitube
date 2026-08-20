<?php

declare(strict_types=1);

namespace SaniTube\Transcription\Contracts;

use SaniTube\Media\Fingerprinters\ChromaprintFingerprinter;
use SaniTube\Transcription\Exceptions\TranscriptionFailed;
use SaniTube\Transcription\TranscriptionResult;

/**
 * A speech-to-text supplier.
 *
 * The same shape as every other provider contract here: the domain expresses an
 * intent and the module decides who serves it, so swapping suppliers is a
 * deployment change rather than a code change and nothing in the catalogue
 * names a vendor.
 *
 * **`none` is a valid configuration and the platform boots without any of
 * these.** Transcription is an enrichment, not a dependency: an installation
 * with no supplier configured ingests, deduplicates, organises and distributes
 * exactly as before, and simply reports that it has not transcribed anything.
 */
interface TranscriptionProvider
{
    /**
     * Configuration name, e.g. "whisper", "none".
     */
    public function name(): string;

    /**
     * The version of *what this provider produces*, not of the vendor's API.
     *
     * Bumped when the meaning of the output changes, so a later pass can tell
     * which stored transcripts it may still trust and which it must redo. The
     * same reason {@see ChromaprintFingerprinter}
     * carries one.
     */
    public function version(): string;

    /**
     * False when the provider has no usable credentials or no reachable
     * endpoint. Callers check this rather than discovering it through an
     * exception mid-workflow.
     */
    public function isAvailable(): bool;

    /**
     * @param  string  $localPath  a file on local disk; providers never receive a URL,
     *                             so nothing here can be talked into fetching one
     * @param  string|null  $languageHint  what the operator believes it is, if anything.
     *                                     A hint, never an assertion — a provider is free
     *                                     to report something else, and that disagreement
     *                                     is information rather than an error.
     *
     * @throws TranscriptionFailed
     */
    public function transcribe(string $localPath, ?string $languageHint = null): TranscriptionResult;
}
