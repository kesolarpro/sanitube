<?php

declare(strict_types=1);

namespace SaniTube\Media\Contracts;

use SaniTube\Media\Exceptions\FingerprintFailed;
use SaniTube\Media\FingerprintResult;

/**
 * A fingerprinter that can work from a storage key.
 *
 * See {@see AnalyzesStoredObject} — same reasoning, same shape. A remote
 * fingerprinter is handed a key and the worker reads the object itself; Core
 * never touches the bytes.
 */
interface FingerprintsStoredObject
{
    /**
     * @throws FingerprintFailed
     */
    public function fingerprintStored(string $storageKey): FingerprintResult;
}
