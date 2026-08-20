<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Exceptions;

use DomainException;
use SaniTube\Catalog\Enums\ExternalIdentifierType;

/**
 * Raised when an identifier operation would damage the catalogue's memory of
 * what the outside world calls something.
 *
 * **Every case carries a machine-readable `reason`**, per REL-002. Until
 * CAT-005 this exception had only an English sentence, which was survivable
 * while its only readers were tests — and stopped being survivable the moment a
 * screen had to tell "that is not a well-formed ISRC" apart from "this track
 * already has one". A caller that has to match on a sentence works around the
 * check rather than fixing it, and a reworded message silently changes
 * behaviour. That is the mistake `SubmitDelivery` made with
 * DISTRIBUTOR_UNREACHABLE and REL-002 removed.
 *
 * The messages stay as they are. They go to a log, and one of them names a
 * fully-qualified class — which is a further reason the code is what travels.
 */
final class ExternalIdentifierException extends DomainException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $fields
     */
    public static function immutable(array $fields): self
    {
        return new self(sprintf(
            'External identifiers are immutable; refused change to: %s. '
                .'Revoke the identifier and assign a new one instead.',
            implode(', ', $fields),
        ), 'IDENTIFIER_IMMUTABLE');
    }

    public static function deletionForbidden(): self
    {
        return new self(
            'External identifiers cannot be deleted. An identifier that reached a distributor exists '
                .'in reports and store metadata SaniTube does not control; revoke it instead.',
            'IDENTIFIER_DELETION_FORBIDDEN',
        );
    }

    public static function reactivationForbidden(): self
    {
        return new self('A revoked external identifier cannot be reactivated.', 'IDENTIFIER_REACTIVATION_FORBIDDEN');
    }

    public static function alreadyRevoked(string $uuid): self
    {
        return new self(sprintf('External identifier [%s] has already been revoked.', $uuid), 'IDENTIFIER_ALREADY_REVOKED');
    }

    public static function incompatibleEntity(ExternalIdentifierType $type, string $entity): self
    {
        return new self(sprintf(
            'Identifier type [%s] cannot be assigned to [%s].',
            $type->value,
            $entity,
        ), 'IDENTIFIER_INCOMPATIBLE_ENTITY');
    }

    public static function namespaceRequired(ExternalIdentifierType $type): self
    {
        return new self(sprintf(
            'Identifier type [%s] is issued by a counterparty and requires a namespace naming it.',
            $type->value,
        ), 'IDENTIFIER_NAMESPACE_REQUIRED');
    }

    public static function namespaceForbidden(ExternalIdentifierType $type): self
    {
        return new self(sprintf(
            'Identifier type [%s] is globally issued and must use an empty namespace.',
            $type->value,
        ), 'IDENTIFIER_NAMESPACE_FORBIDDEN');
    }

    public static function malformed(ExternalIdentifierType $type, string $value): self
    {
        return new self(sprintf('[%s] is not a well-formed %s.', $value, $type->value), 'IDENTIFIER_MALFORMED');
    }

    public static function activeAuthoritativeExists(ExternalIdentifierType $type, string $existing): self
    {
        return new self(sprintf(
            'This entity already carries an active authoritative %s [%s]. '
                .'Assigning a second one would orphan the identifier already in circulation; revoke it first.',
            $type->value,
            $existing,
        ), 'IDENTIFIER_ALREADY_ACTIVE');
    }
}
