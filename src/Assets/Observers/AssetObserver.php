<?php

declare(strict_types=1);

namespace SaniTube\Assets\Observers;

use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Events\AssetDuplicateDetected;
use SaniTube\Assets\Events\AssetQuarantined;
use SaniTube\Assets\Events\AssetStored;
use SaniTube\Assets\Events\AssetVerified;
use SaniTube\Assets\Exceptions\AssetIntegrityException;
use SaniTube\Assets\Models\Asset;

/**
 * Enforces the asset invariants (I1, I2a–I2e).
 *
 * These live in an observer rather than in a service so that they hold no
 * matter how the row was written — a seeder, a factory, an import job or a
 * future controller. An integrity rule that only one code path respects is not
 * an integrity rule.
 */
final class AssetObserver
{
    public function saving(Asset $asset): void
    {
        $this->assertLineageShape($asset);
        $this->assertNoSelfReference($asset);
        $this->assertNoCycles($asset);
    }

    public function updating(Asset $asset): void
    {
        // I1 — the frozen fields are checked against the *original* status, so
        // that the same save cannot both relax the status and rewrite the
        // bytes it describes.
        $originalStatus = $asset->getOriginal('status');
        $status = $originalStatus instanceof AssetStatus
            ? $originalStatus
            : AssetStatus::tryFrom((string) $originalStatus);

        if ($status === null || ! $status->isImmutable()) {
            return;
        }

        $changed = array_values(array_intersect(Asset::IMMUTABLE_ONCE_STORED, array_keys($asset->getDirty())));

        if ($changed !== []) {
            throw AssetIntegrityException::immutable($status->value, $changed);
        }
    }

    public function created(Asset $asset): void
    {
        $this->emitStatusEvents($asset);

        if ($asset->duplicate_of_asset_id !== null) {
            event(new AssetDuplicateDetected($asset));
        }
    }

    public function updated(Asset $asset): void
    {
        if ($asset->wasChanged('status')) {
            $this->emitStatusEvents($asset);
        }

        if ($asset->wasChanged('duplicate_of_asset_id') && $asset->duplicate_of_asset_id !== null) {
            event(new AssetDuplicateDetected($asset));
        }
    }

    /**
     * I2a and I2b/I2c.
     */
    private function assertLineageShape(Asset $asset): void
    {
        if ($asset->kind->forbidsParent() && $asset->parent_asset_id !== null) {
            throw AssetIntegrityException::parentForbidden($asset->kind);
        }

        if ($asset->kind->requiresParent() && $asset->parent_asset_id === null) {
            throw AssetIntegrityException::parentRequired($asset->kind);
        }
    }

    /**
     * I2d.
     */
    private function assertNoSelfReference(Asset $asset): void
    {
        if ($asset->exists && $asset->parent_asset_id === $asset->id) {
            throw AssetIntegrityException::selfReference('parent_asset_id');
        }

        if ($asset->exists && $asset->duplicate_of_asset_id === $asset->id) {
            throw AssetIntegrityException::selfReference('duplicate_of_asset_id');
        }
    }

    /**
     * I2e — walk each chain and refuse anything that comes back round.
     */
    private function assertNoCycles(Asset $asset): void
    {
        foreach (['parent_asset_id', 'duplicate_of_asset_id'] as $column) {
            $startId = $asset->{$column};

            if ($startId === null || ! $asset->exists) {
                continue;
            }

            if ($this->chainReaches((int) $startId, (int) $asset->id, $column)) {
                throw AssetIntegrityException::cycle($column);
            }
        }
    }

    private function chainReaches(int $fromId, int $targetId, string $column): bool
    {
        $seen = [];
        $currentId = $fromId;

        // Bounded by `seen`: a pre-existing cycle in the data terminates the
        // walk instead of hanging the request.
        while ($currentId !== 0) {
            if ($currentId === $targetId) {
                return true;
            }

            if (isset($seen[$currentId])) {
                return false;
            }

            $seen[$currentId] = true;

            $next = Asset::query()->whereKey($currentId)->value($column);

            if ($next === null) {
                return false;
            }

            $currentId = (int) $next;
        }

        return false;
    }

    private function emitStatusEvents(Asset $asset): void
    {
        match ($asset->status) {
            AssetStatus::Stored => event(new AssetStored($asset)),
            AssetStatus::Verified => event(new AssetVerified($asset)),
            AssetStatus::Quarantined => event(new AssetQuarantined($asset)),
            default => null,
        };
    }
}
