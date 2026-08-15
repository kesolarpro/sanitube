<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Services;

use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Exceptions\ExternalIdentifierException;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\Track;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Releases\Models\Release;

/**
 * Brings identifiers to one canonical form before they are stored.
 *
 * Registry identifiers are written a dozen different ways in the wild —
 * `FR-Z03-14-00001`, `FRZ031400001`, `frz03 14 00001` are the same ISRC. If
 * those reach the database unchanged, the uniqueness constraints are worthless:
 * the same identifier would be storable three times, and a legacy import would
 * happily mint a second ISRC for a recording that already has one.
 *
 * Vendor identifiers are the opposite case and are only trimmed. Their format
 * belongs to the vendor, and "correcting" it would break the lookup it exists
 * for.
 */
final class ExternalIdentifierNormaliser
{
    /**
     * Which entity classes each globally-issued type may be attached to.
     *
     * Types absent from this map are unconstrained: a distributor or DSP id
     * can legitimately describe a track, a release or something not yet
     * modelled.
     *
     * @var array<string, list<class-string>>
     */
    private const ENTITY_RULES = [
        ExternalIdentifierType::Isrc->value => [Track::class],
        ExternalIdentifierType::Upc->value => [Release::class],
        ExternalIdentifierType::Ean->value => [Release::class],
        ExternalIdentifierType::Iswc->value => [Composition::class],
        ExternalIdentifierType::Ipi->value => [Contributor::class],
        ExternalIdentifierType::Isni->value => [Contributor::class],
    ];

    public function normaliseValue(ExternalIdentifierType $type, string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw ExternalIdentifierException::malformed($type, $value);
        }

        return match ($type) {
            ExternalIdentifierType::Isrc => $this->isrc($value),
            ExternalIdentifierType::Upc => $this->digits($type, $value, 12),
            ExternalIdentifierType::Ean => $this->digits($type, $value, 13),
            ExternalIdentifierType::Iswc => $this->iswc($value),
            ExternalIdentifierType::Ipi => $this->ipi($value),
            ExternalIdentifierType::Isni => $this->isni($value),
            ExternalIdentifierType::Grid => $this->grid($value),

            // Opaque to SaniTube: the counterparty owns the format.
            default => $value,
        };
    }

    /**
     * Namespaces name a counterparty, so they are compared case-insensitively
     * and never carry surrounding whitespace.
     */
    public function normaliseNamespace(ExternalIdentifierType $type, string $namespace): string
    {
        $namespace = strtolower(trim($namespace));

        if ($type->isGlobal()) {
            if ($namespace !== '') {
                throw ExternalIdentifierException::namespaceForbidden($type);
            }

            return '';
        }

        if ($namespace === '') {
            throw ExternalIdentifierException::namespaceRequired($type);
        }

        return $namespace;
    }

    /**
     * @param  class-string  $entityClass
     */
    public function assertEntityCompatible(ExternalIdentifierType $type, string $entityClass): void
    {
        $allowed = self::ENTITY_RULES[$type->value] ?? null;

        if ($allowed === null) {
            return;
        }

        foreach ($allowed as $allowedClass) {
            if (is_a($entityClass, $allowedClass, true)) {
                return;
            }
        }

        throw ExternalIdentifierException::incompatibleEntity($type, class_basename($entityClass));
    }

    /**
     * ISRC: two-letter country, three-character registrant, two-digit year,
     * five-digit designation.
     */
    private function isrc(string $value): string
    {
        $candidate = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        if (preg_match('/^[A-Z]{2}[A-Z0-9]{3}[0-9]{7}$/', $candidate) !== 1) {
            throw ExternalIdentifierException::malformed(ExternalIdentifierType::Isrc, $value);
        }

        return $candidate;
    }

    private function digits(ExternalIdentifierType $type, string $value, int $length): string
    {
        $candidate = (string) preg_replace('/\D/', '', $value);

        if (strlen($candidate) !== $length) {
            throw ExternalIdentifierException::malformed($type, $value);
        }

        return $candidate;
    }

    /**
     * ISWC: T followed by nine digits and a check digit.
     */
    private function iswc(string $value): string
    {
        $candidate = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        if (preg_match('/^T[0-9]{10}$/', $candidate) !== 1) {
            throw ExternalIdentifierException::malformed(ExternalIdentifierType::Iswc, $value);
        }

        return $candidate;
    }

    /**
     * IPI name numbers are issued as nine digits and increasingly as eleven;
     * both are in circulation and both are accepted.
     */
    private function ipi(string $value): string
    {
        $candidate = (string) preg_replace('/\D/', '', $value);

        if (preg_match('/^[0-9]{9,11}$/', $candidate) !== 1) {
            throw ExternalIdentifierException::malformed(ExternalIdentifierType::Ipi, $value);
        }

        return $candidate;
    }

    /**
     * ISNI: sixteen characters, the last of which may be X.
     */
    private function isni(string $value): string
    {
        $candidate = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        if (preg_match('/^[0-9]{15}[0-9X]$/', $candidate) !== 1) {
            throw ExternalIdentifierException::malformed(ExternalIdentifierType::Isni, $value);
        }

        return $candidate;
    }

    /**
     * GRid: scheme, issuer, release number and a check character — eighteen
     * characters in total.
     */
    private function grid(string $value): string
    {
        $candidate = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        if (preg_match('/^A1[A-Z0-9]{16}$/', $candidate) !== 1) {
            throw ExternalIdentifierException::malformed(ExternalIdentifierType::Grid, $value);
        }

        return $candidate;
    }
}
