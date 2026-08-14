<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Exceptions\AssetIntegrityException;
use SaniTube\Assets\Models\Asset;
use Tests\TestCase;

/**
 * Invariants I1 and I2a–I2e.
 */
final class AssetInvariantsTest extends TestCase
{
    use RefreshDatabase;

    // I2a

    #[Test]
    public function an_audio_master_refuses_a_parent(): void
    {
        $parent = Asset::factory()->master()->create();

        $this->expectException(AssetIntegrityException::class);
        $this->expectExceptionMessage('cannot have a parent asset');

        Asset::factory()->create([
            'kind' => AssetKind::AudioMaster,
            'parent_asset_id' => $parent->id,
        ]);
    }

    // I2b

    #[Test]
    public function an_audio_derivative_requires_a_parent(): void
    {
        $this->expectException(AssetIntegrityException::class);
        $this->expectExceptionMessage('requires a parent asset');

        Asset::factory()->create(['kind' => AssetKind::AudioDerivative, 'parent_asset_id' => null]);
    }

    // I2c

    #[Test]
    public function a_preview_requires_a_parent(): void
    {
        $this->expectException(AssetIntegrityException::class);

        Asset::factory()->create(['kind' => AssetKind::Preview, 'parent_asset_id' => null]);
    }

    #[Test]
    public function a_derivative_with_a_parent_is_accepted(): void
    {
        $master = Asset::factory()->master()->verified()->create();

        $derivative = Asset::factory()->derivativeOf($master)->create();

        $this->assertSame($master->id, $derivative->parent_asset_id);
    }

    // I2d

    #[Test]
    public function an_asset_cannot_be_its_own_parent(): void
    {
        $asset = Asset::factory()->derivativeOf(Asset::factory()->master()->create())->create();

        $this->expectException(AssetIntegrityException::class);
        $this->expectExceptionMessage('cannot be its own [parent_asset_id]');

        $asset->parent_asset_id = $asset->id;
        $asset->save();
    }

    #[Test]
    public function an_asset_cannot_be_its_own_duplicate(): void
    {
        $asset = Asset::factory()->master()->create();

        $this->expectException(AssetIntegrityException::class);
        $this->expectExceptionMessage('cannot be its own [duplicate_of_asset_id]');

        $asset->duplicate_of_asset_id = $asset->id;
        $asset->save();
    }

    // I2e

    #[Test]
    public function parent_chains_cannot_close_a_cycle(): void
    {
        $master = Asset::factory()->master()->create();
        $first = Asset::factory()->derivativeOf($master)->create();
        $second = Asset::factory()->derivativeOf($first)->create();

        // Pointing the master at its own grandchild would make the lineage
        // circular and any "walk up to the master" traversal non-terminating.
        $this->expectException(AssetIntegrityException::class);
        $this->expectExceptionMessage('would close a cycle');

        $master->kind = AssetKind::AudioDerivative;
        $master->parent_asset_id = $second->id;
        $master->save();
    }

    #[Test]
    public function duplicate_chains_cannot_close_a_cycle(): void
    {
        $first = Asset::factory()->master()->create();
        $second = Asset::factory()->master()->create(['duplicate_of_asset_id' => $first->id]);

        $this->expectException(AssetIntegrityException::class);
        $this->expectExceptionMessage('would close a cycle');

        $first->duplicate_of_asset_id = $second->id;
        $first->save();
    }

    // I1

    #[Test]
    public function a_stored_asset_cannot_have_its_bytes_redescribed(): void
    {
        $asset = Asset::factory()->master()->stored()->create();

        $this->expectException(AssetIntegrityException::class);
        $this->expectExceptionMessage('refused change to: path');

        $asset->path = 'masters/somewhere-else.wav';
        $asset->save();
    }

    #[Test]
    public function a_verified_asset_cannot_have_its_checksum_rewritten(): void
    {
        // Rewriting a checksum is how a verified master silently becomes a
        // different file while still claiming to be verified.
        $asset = Asset::factory()->master()->verified()->create();

        $this->expectException(AssetIntegrityException::class);
        $this->expectExceptionMessage('refused change to: sha256');

        $asset->sha256 = hash('sha256', 'something else');
        $asset->save();
    }

    #[Test]
    public function a_verified_asset_cannot_change_kind_disk_or_size(): void
    {
        $asset = Asset::factory()->master()->verified()->create();

        foreach (['disk' => 'other', 'byte_size' => 1, 'kind' => AssetKind::Artwork] as $field => $value) {
            $fresh = $asset->fresh();
            $this->assertNotNull($fresh);

            try {
                $fresh->{$field} = $value;
                $fresh->save();
                $this->fail(sprintf('Expected [%s] to be immutable on a verified asset.', $field));
            } catch (AssetIntegrityException $exception) {
                $this->assertStringContainsString($field, $exception->getMessage());
            }
        }
    }

    #[Test]
    public function a_pending_asset_is_still_editable(): void
    {
        // Immutability begins at STORED, not at creation: an asset being
        // prepared must remain correctable.
        $asset = Asset::factory()->master()->create();

        $asset->path = 'masters/corrected.wav';
        $asset->save();

        $this->assertSame('masters/corrected.wav', $asset->fresh()?->path);
    }

    #[Test]
    public function a_stored_asset_may_still_progress_to_verified(): void
    {
        $asset = Asset::factory()->master()->stored()->create();

        $asset->status = AssetStatus::Verified;
        $asset->verified_at = now();
        $asset->save();

        $this->assertTrue($asset->fresh()?->isVerifiedMaster());
    }

    #[Test]
    public function identical_bytes_may_be_recorded_twice_as_a_duplicate(): void
    {
        // The same master legitimately arrives twice. Rejecting the row would
        // lose the fact that it happened; recording it as a duplicate keeps it.
        $original = Asset::factory()->master()->verified()->create();

        $copy = Asset::factory()->master()->create([
            'sha256' => $original->sha256,
            'duplicate_of_asset_id' => $original->id,
        ]);

        $this->assertSame($original->sha256, $copy->sha256);
        $this->assertSame($original->id, $copy->duplicate_of_asset_id);
    }
}
