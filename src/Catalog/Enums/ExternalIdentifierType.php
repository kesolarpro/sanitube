<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Enums;

/**
 * Every identifier the catalogue knows about, canonical and vendor-issued
 * alike.
 *
 * The distinction that matters is `isGlobal()`. A global identifier is issued
 * by a worldwide registry and means the same thing everywhere, so it carries
 * an empty namespace and must be unique across the whole catalogue. A
 * namespaced identifier is issued by some counterparty, so the same value can
 * legitimately exist under two different namespaces — which is precisely why
 * the namespace is part of every uniqueness rule rather than an afterthought.
 */
enum ExternalIdentifierType: string
{
    case Isrc = 'ISRC';
    case Upc = 'UPC';
    case Ean = 'EAN';
    case Iswc = 'ISWC';
    case Ipi = 'IPI';
    case Isni = 'ISNI';
    case Grid = 'GRID';
    case DistributorReleaseId = 'DISTRIBUTOR_RELEASE_ID';
    case DistributorTrackId = 'DISTRIBUTOR_TRACK_ID';
    case DspId = 'DSP_ID';
    case DspUrl = 'DSP_URL';

    /**
     * Issued by a worldwide registry: namespace must be empty.
     */
    public function isGlobal(): bool
    {
        return match ($this) {
            self::Isrc, self::Upc, self::Ean, self::Iswc, self::Ipi, self::Isni, self::Grid => true,
            default => false,
        };
    }

    /**
     * Issued by a counterparty: namespace names that counterparty and is
     * required, otherwise two distributors' ids would collide.
     */
    public function requiresNamespace(): bool
    {
        return ! $this->isGlobal();
    }
}
