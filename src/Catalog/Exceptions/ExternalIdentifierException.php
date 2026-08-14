<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Exceptions;

use DomainException;
use SaniTube\Catalog\Enums\ExternalIdentifierType;

/**
 * Raised when an identifier operation would damage the catalogue's memory of
 * what the outside world calls something.
 */
final class ExternalIdentifierException extends DomainException
{
    /**
     * @param  list<string>  $fields
     */
    public static function immutable(array $fields): self
    {
        return new self(sprintf(
            'External identifiers are immutable; refused change to: %s. '
                .'Revoke the identifier and assign a new one instead.',
            implode(', ', $fields),
        ));
    }

    public static function deletionForbidden(): self
    {
        return new self(
            'External identifiers cannot be deleted. An identifier that reached a distributor exists '
                .'in reports and store metadata SaniTube does not control; revoke it instead.',
        );
    }

    public static function reactivationForbidden(): self
    {
        return new self('A revoked external identifier cannot be reactivated.');
    }

    public static function alreadyRevoked(string $uuid): self
    {
        return new self(sprintf('External identifier [%s] has already been revoked.', $uuid));
    }

    public static function incompatibleEntity(ExternalIdentifierType $type, string $entity): self
    {
        return new self(sprintf(
            'Identifier type [%s] cannot be assigned to [%s].',
            $type->value,
            $entity,
        ));
    }

    public static function namespaceRequired(ExternalIdentifierType $type): self
    {
        return new self(sprintf(
            'Identifier type [%s] is issued by a counterparty and requires a namespace naming it.',
            $type->value,
        ));
    }

    public static function namespaceForbidden(ExternalIdentifierType $type): self
    {
        return new self(sprintf(
            'Identifier type [%s] is globally issued and must use an empty namespace.',
            $type->value,
        ));
    }

    public static function malformed(ExternalIdentifierType $type, string $value): self
    {
        return new self(sprintf('[%s] is not a well-formed %s.', $value, $type->value));
    }

    public static function activeAuthoritativeExists(ExternalIdentifierType $type, string $existing): self
    {
        return new self(sprintf(
            'This entity already carries an active authoritative %s [%s]. '
                .'Assigning a second one would orphan the identifier already in circulation; revoke it first.',
            $type->value,
            $existing,
        ));
    }
}
