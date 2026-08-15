<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Enums;

/**
 * Where material entered the platform.
 *
 * Provenance, not a processing mode. It is recorded on the item that brought
 * bytes in and carried onto the candidate, so that months later "where did
 * this recording come from?" has an answer that does not depend on anyone
 * having written it down.
 *
 * `GENERATED` and `LEGACY_IMPORT` are declared and not implemented. They exist
 * now because the column that stores them must know its whole range before the
 * first row is written, and because a later ticket adding a case to a
 * populated enum is a migration rather than a line of code.
 */
enum IngestionSource: string
{
    case ManualUpload = 'MANUAL_UPLOAD';
    case CloudImport = 'CLOUD_IMPORT';
    case Generated = 'GENERATED';
    case LegacyImport = 'LEGACY_IMPORT';

    /**
     * Whether ING-001 can actually take material in from here.
     */
    public function isImplemented(): bool
    {
        return $this === self::ManualUpload || $this === self::CloudImport;
    }

    /**
     * Whether the reference names an object the platform already holds, as
     * opposed to one a client is about to hand over.
     */
    public function readsFromStorage(): bool
    {
        return $this === self::CloudImport || $this === self::ManualUpload;
    }
}
