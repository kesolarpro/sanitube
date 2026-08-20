<?php

declare(strict_types=1);

namespace SaniTube\Catalog;

use Illuminate\Database\Eloquent\Model;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\Track;
use SaniTube\Releases\Models\Release;

/**
 * Which identifiers a person may type in, and on what.
 *
 * **A narrower rule than the normaliser's, and a different one.**
 * `ExternalIdentifierNormaliser::assertEntityCompatible()` answers "can this
 * type describe this kind of thing" — a question about the registries. This
 * answers "may a human hand this to the platform", which is a question about
 * provenance, and the two are not the same list.
 *
 * The types deliberately absent are the ones SaniTube *receives* rather than
 * holds: DISTRIBUTOR_RELEASE_ID, DISTRIBUTOR_TRACK_ID, DSP_ID and DSP_URL. Each
 * of those is written by the delivery engine and records what somebody else's
 * system called this release. A form that accepted one would let a person type
 * a delivery reference for a delivery that never happened — and the platform
 * would then show it beside references it genuinely received, with no way to
 * tell them apart. DIST-001-H1 already had to solve exactly this for the manual
 * resolution path, and solved it by giving the operator's answer its own
 * provenance rather than letting it impersonate the distributor's.
 *
 * GRID is absent for a plainer reason: SaniTube has no release-group concept to
 * attach one to yet, so offering the field would be offering a value with
 * nowhere to be read from.
 */
final class ManuallyAssignableIdentifiers
{
    /**
     * @var array<class-string, list<ExternalIdentifierType>>
     */
    private const BY_ENTITY = [
        Release::class => [ExternalIdentifierType::Upc, ExternalIdentifierType::Ean],
        Track::class => [ExternalIdentifierType::Isrc],
    ];

    /**
     * @return list<ExternalIdentifierType>
     */
    public static function for(Model $entity): array
    {
        return self::BY_ENTITY[$entity::class] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function valuesFor(Model $entity): array
    {
        return array_map(
            static fn (ExternalIdentifierType $type): string => $type->value,
            self::for($entity),
        );
    }

    /**
     * The only place a type from a request becomes a type.
     *
     * Returns null rather than throwing, and never trusts the string: a type
     * that is not on this entity's list is refused whatever it is, so a request
     * naming DSP_ID is indistinguishable from one naming nonsense.
     */
    public static function resolve(Model $entity, string $type): ?ExternalIdentifierType
    {
        foreach (self::for($entity) as $candidate) {
            if ($candidate->value === $type) {
                return $candidate;
            }
        }

        return null;
    }
}
