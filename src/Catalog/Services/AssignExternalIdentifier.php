<?php

declare(strict_types=1);

namespace SaniTube\Catalog\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Events\ExternalIdentifierAssigned;
use SaniTube\Catalog\Exceptions\ExternalIdentifierException;
use SaniTube\Catalog\Models\ExternalIdentifier;

/**
 * The only supported way to attach an outside identifier to something in the
 * catalogue (I5).
 *
 * The guarantee that matters most here is the legacy one. A large part of the
 * initial catalogue was already distributed elsewhere and carries ISRCs that
 * exist in store metadata and royalty statements SaniTube does not control.
 * Minting a second ISRC for one of those recordings would not be a duplicate
 * row — it would split that recording's earnings between two identifiers, and
 * the damage would only surface months later in a royalty report that no
 * longer reconciles.
 *
 * (Which distributor issued them is deliberately not named here: the domain
 * must stay readable without knowing who any supplier is. That belongs in an
 * adapter, or in an identifier's namespace.)
 *
 * So a second active identifier of the same type and namespace is refused
 * here, and refused again by a unique index underneath. The service gives the
 * caller a useful message; the index makes the rule true regardless of caller.
 */
final readonly class AssignExternalIdentifier
{
    public function __construct(private ExternalIdentifierNormaliser $normaliser) {}

    public function __invoke(
        Model $entity,
        ExternalIdentifierType $type,
        string $value,
        ExternalIdentifierSource $source = ExternalIdentifierSource::Manual,
        string $namespace = '',
        bool $isAuthoritative = false,
        ?\DateTimeInterface $assignedAt = null,
    ): ExternalIdentifier {
        $this->normaliser->assertEntityCompatible($type, $entity::class);

        $namespace = $this->normaliser->normaliseNamespace($type, $namespace);
        $value = $this->normaliser->normaliseValue($type, $value);

        return DB::transaction(function () use ($entity, $type, $value, $namespace, $source, $isAuthoritative, $assignedAt): ExternalIdentifier {
            $existing = $this->activeIdentifier($entity, $type, $namespace);

            if ($existing !== null) {
                // Idempotence: re-assigning exactly what is already recorded is
                // a no-op, so a re-run of an import does not fail halfway.
                if ($existing->value === $value) {
                    return $existing;
                }

                throw ExternalIdentifierException::activeAuthoritativeExists($type, $existing->value);
            }

            $identifier = new ExternalIdentifier([
                'identifiable_type' => $entity->getMorphClass(),
                'identifiable_id' => $entity->getKey(),
                'type' => $type,
                'namespace' => $namespace,
                'value' => $value,
                'is_authoritative' => $isAuthoritative,
                'source' => $source,
                'assigned_at' => $assignedAt ?? now(),
                'active_marker' => ExternalIdentifier::ACTIVE,
            ]);

            $identifier->save();

            event(new ExternalIdentifierAssigned($identifier));

            return $identifier;
        });
    }

    /**
     * Whether the entity already carries an active identifier of this kind —
     * the question a legacy import must ask before it considers minting one.
     */
    public function hasActive(Model $entity, ExternalIdentifierType $type, string $namespace = ''): bool
    {
        return $this->activeIdentifier($entity, $type, $namespace) instanceof ExternalIdentifier;
    }

    private function activeIdentifier(
        Model $entity,
        ExternalIdentifierType $type,
        string $namespace,
    ): ?ExternalIdentifier {
        return ExternalIdentifier::query()
            ->where('identifiable_type', $entity->getMorphClass())
            ->where('identifiable_id', $entity->getKey())
            ->where('type', $type->value)
            ->where('namespace', $namespace)
            ->whereNotNull('active_marker')
            ->first();
    }
}
