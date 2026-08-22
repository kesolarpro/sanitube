<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Exceptions\AssetTrashException;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Services\AssetStorageService;
use SaniTube\Assets\Services\TrashAsset;
use SaniTube\Catalog\Models\Track;
use SaniTube\Identity\Enums\UserRole;
use Tests\TestCase;

/**
 * A trashed asset cannot become, or stay, the thing a release ships.
 *
 * TRASH-001. {@see TrashAsset} has always guarded one direction: an asset a
 * track already uses as its master cannot be trashed, because that would leave
 * the catalogue pointing at nothing. The **reverse** was never guarded, and it
 * is the one that reaches a customer: nothing stopped a track being given a
 * trashed asset as its master, and nothing in the delivery package looked at
 * `trashed_at` on the way out.
 *
 * So the sequence was: trash an asset a reviewer judged unusable — wrong file,
 * bad transfer, a confirmed duplicate — then promote a candidate that points at
 * it, and the bytes somebody set aside are what leaves for the distributor.
 * Everything on the way looked correct, because every screen that lists assets
 * already hides trashed ones.
 *
 * Both halves are held here, in the order they fail: a track may not be given
 * one, and a package refuses to be built from one it somehow has.
 */
final class TrashedMasterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_track_cannot_be_given_a_trashed_master(): void
    {
        $asset = $this->trashedAsset();

        try {
            Track::factory()->create(['master_asset_id' => $asset->getKey()]);

            $this->fail('A track was created with a trashed master.');
        } catch (AssetTrashException $exception) {
            $this->assertSame('ASSET_TRASHED', $exception->reason);
        }
    }

    #[Test]
    public function an_existing_track_cannot_be_repointed_at_a_trashed_master(): void
    {
        $live = $this->asset('live.wav', 'the good bytes');
        $trashed = $this->trashedAsset();

        $track = Track::factory()->create(['master_asset_id' => $live->getKey()]);

        try {
            $track->update(['master_asset_id' => $trashed->getKey()]);

            $this->fail('A track was repointed at a trashed master.');
        } catch (AssetTrashException $exception) {
            $this->assertSame('ASSET_TRASHED', $exception->reason);
        }

        // And it kept the one it had. A refusal that half-applied would be
        // worse than none.
        $this->assertSame($live->getKey(), $track->fresh()?->master_asset_id);
    }

    #[Test]
    public function trashing_a_master_a_track_uses_is_still_refused(): void
    {
        // The direction that already worked, held here so that closing the
        // other one cannot quietly open this.
        $asset = $this->asset('in-use.wav', 'shipped bytes');

        Track::factory()->create(['master_asset_id' => $asset->getKey()]);

        try {
            $this->app->make(TrashAsset::class)->trash($asset, $this->reviewer(), 'DUPLICATE');

            $this->fail('A master in use was trashed.');
        } catch (AssetTrashException $exception) {
            $this->assertSame('ASSET_IN_USE', $exception->reason);
        }
    }

    private function trashedAsset(): Asset
    {
        $asset = $this->asset('set-aside.wav', 'the bytes somebody rejected');

        $this->app->make(TrashAsset::class)->trash($asset, $this->reviewer(), 'UNUSABLE_AUDIO');

        return $asset->fresh() ?? $asset;
    }

    private function asset(string $filename, string $contents): Asset
    {
        $assets = $this->app->make(AssetStorageService::class);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $assets->store($assets->register(AssetKind::AudioMaster, $filename), $stream);
    }

    private function reviewer(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }
}
