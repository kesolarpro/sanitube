<?php

declare(strict_types=1);

namespace SaniTube\Assets\Exceptions;

use DomainException;
use SaniTube\Assets\Enums\AssetKind;

/**
 * Raised when a change would break the guarantees that make an asset record
 * trustworthy.
 */
final class AssetIntegrityException extends DomainException
{
    /**
     * @param  list<string>  $fields
     */
    public static function immutable(string $status, array $fields): self
    {
        return new self(sprintf(
            'An asset with status [%s] is immutable; refused change to: %s. '
                .'Register a new asset instead of rewriting this one.',
            $status,
            implode(', ', $fields),
        ));
    }

    public static function parentForbidden(AssetKind $kind): self
    {
        return new self(sprintf(
            '[%s] is the top of a lineage and cannot have a parent asset.',
            $kind->value,
        ));
    }

    public static function parentRequired(AssetKind $kind): self
    {
        return new self(sprintf(
            '[%s] is derived and requires a parent asset, otherwise it cannot be traced to a master.',
            $kind->value,
        ));
    }

    public static function selfReference(string $relation): self
    {
        return new self(sprintf('An asset cannot be its own [%s].', $relation));
    }

    public static function cycle(string $relation): self
    {
        return new self(sprintf(
            'The requested [%s] would close a cycle; asset lineage must stay acyclic.',
            $relation,
        ));
    }
}
