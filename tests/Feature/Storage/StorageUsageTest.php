<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ui\Queries\StorageUsageQuery;
use Tests\TestCase;

/**
 * How much this installation is keeping, and what it refuses to claim.
 *
 * STO-005. `byte_size` has been on every asset since the first upload and the
 * question was asked nowhere: an operator could not find out how much they
 * were storing without a database client, which on a metered object store is
 * the number that becomes a bill. The dashboard reported an asset *count*,
 * which says nothing about it — a thousand previews and a thousand masters are
 * the same number and three orders of magnitude apart.
 *
 * Four claims carry these tests, and three are about what the panel will not
 * say.
 *
 *   - **Only bytes the platform believes are there** count as stored. An
 *     upload that never finished has a row and a declared size, and counting
 *     it would report bytes nobody stored.
 *   - **The trash is apart and never folded in.** Those bytes still cost, and
 *     an operator watching a total that will not go down needs to know how
 *     much of it is waiting on a decision they have not made.
 *   - **No capacity, and no percentage.** There is no denominator this
 *     platform can honestly obtain, and inventing one from a free tier would
 *     be a guess with a progress bar drawn around it.
 *   - **Unmeasured is not zero.** An installation storing nothing and one that
 *     could not be asked are different answers.
 */
final class StorageUsageTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------- what is counted

    #[Test]
    public function it_counts_the_bytes_the_platform_believes_are_there(): void
    {
        $this->asset(AssetStatus::Stored, bytes: 1000);
        $this->asset(AssetStatus::Verified, bytes: 2000);

        $usage = $this->usage();

        $this->assertTrue($usage['measured']);
        $this->assertSame(3000, $usage['held']['bytes']);
        $this->assertSame(2, $usage['held']['assets']);
    }

    #[Test]
    public function an_upload_that_never_finished_is_not_storage(): void
    {
        // A PENDING row carries a declared size for bytes nobody has stored.
        // Counting it would report a number that goes down when an upload
        // fails, which reads as somebody having deleted something.
        $this->asset(AssetStatus::Stored, bytes: 1000);
        $this->asset(AssetStatus::Pending, bytes: 9_000_000);

        $usage = $this->usage();

        $this->assertSame(1000, $usage['held']['bytes']);
        $this->assertSame(9_000_000, $usage['unsure']['bytes']);
    }

    #[Test]
    public function an_object_the_platform_can_no_longer_find_is_not_storage_either(): void
    {
        $this->asset(AssetStatus::Stored, bytes: 1000);
        $this->asset(AssetStatus::Missing, bytes: 5000);

        $usage = $this->usage();

        $this->assertSame(1000, $usage['held']['bytes']);

        // Pending and missing together. Both mean the platform cannot vouch
        // for the bytes, and somebody chasing a discrepancy against an invoice
        // wants the size of the uncertainty rather than its two causes.
        $this->assertSame(5000, $usage['unsure']['bytes']);
    }

    // ------------------------------------------------------------- the trash

    #[Test]
    public function trashed_bytes_are_counted_and_kept_apart(): void
    {
        $this->asset(AssetStatus::Verified, bytes: 1000);
        $this->asset(AssetStatus::Verified, bytes: 4000, trashed: true);

        $usage = $this->usage();

        // Never folded into the stored figure: a total that silently included
        // the trash would be one an operator could not act on.
        $this->assertSame(1000, $usage['held']['bytes']);
        $this->assertSame(4000, $usage['trashed']['bytes']);
        $this->assertSame(1, $usage['trashed']['assets']);
    }

    #[Test]
    public function trashed_bytes_are_left_out_of_the_kind_breakdown(): void
    {
        // The question this breakdown answers is which kind is worth doing
        // something about, and the trash has its own answer above.
        $this->asset(AssetStatus::Verified, bytes: 1000, kind: AssetKind::Artwork);
        $this->asset(AssetStatus::Verified, bytes: 7000, kind: AssetKind::Artwork, trashed: true);

        $this->assertSame(1000, $this->usage()['by_kind'][AssetKind::Artwork->value]['bytes']);
    }

    // -------------------------------------------------------- the breakdowns

    #[Test]
    public function every_kind_is_present_including_the_zeros(): void
    {
        $this->asset(AssetStatus::Verified, bytes: 1000, kind: AssetKind::AudioMaster);

        $byKind = $this->usage()['by_kind'];

        // A catalogue with no video looks identical to one whose video figure
        // went missing if absent keys render as absent.
        foreach (AssetKind::cases() as $case) {
            $this->assertArrayHasKey($case->value, $byKind, $case->value.' is missing from the breakdown.');
        }

        $this->assertSame(0, $byKind[AssetKind::Video->value]['bytes']);
    }

    #[Test]
    public function two_disks_are_reported_separately(): void
    {
        // The ordinary outcome of moving to object storage: the new provider
        // takes what arrives next and what came before stays where it was.
        // Somebody paying two bills at once is who this is for.
        $this->asset(AssetStatus::Verified, bytes: 1000, disk: 'local');
        $this->asset(AssetStatus::Verified, bytes: 2000, disk: 'r2');
        $this->asset(AssetStatus::Verified, bytes: 500, disk: 'r2', trashed: true);

        $byDisk = $this->usage()['by_disk'];

        $this->assertCount(2, $byDisk);
        $this->assertSame(['disk' => 'local', 'bytes' => 1000, 'assets' => 1, 'trashed_bytes' => 0], $byDisk[0]);
        $this->assertSame(['disk' => 'r2', 'bytes' => 2500, 'assets' => 2, 'trashed_bytes' => 500], $byDisk[1]);
    }

    #[Test]
    public function the_kind_breakdown_adds_up_to_the_stored_figure(): void
    {
        // A breakdown that does not sum to its own total is one somebody stops
        // trusting the first time they add it up.
        $this->asset(AssetStatus::Verified, bytes: 1000, kind: AssetKind::AudioMaster);
        $this->asset(AssetStatus::Stored, bytes: 2000, kind: AssetKind::Artwork);
        // Not a preview or a derivative: both require a parent, and the sum
        // this test is about has nothing to do with lineage.
        $this->asset(AssetStatus::Verified, bytes: 400, kind: AssetKind::Lyrics);
        $this->asset(AssetStatus::Pending, bytes: 99_000, kind: AssetKind::Video);
        $this->asset(AssetStatus::Verified, bytes: 88_000, kind: AssetKind::Stem, trashed: true);

        $usage = $this->usage();

        $summed = array_sum(array_column($usage['by_kind'], 'bytes'));

        $this->assertSame($usage['held']['bytes'], $summed);
        $this->assertSame(3400, $summed);
    }

    #[Test]
    public function a_kind_this_build_has_no_case_for_is_still_counted(): void
    {
        // The shape of a downgrade: a kind added in a later release leaves
        // rows behind that this build's enum has never heard of. Dropping them
        // would be bytes somebody is paying for, silently missing from a total
        // that then does not add up to itself.
        $known = $this->asset(AssetStatus::Verified, bytes: 1000, kind: AssetKind::AudioMaster);
        $stranger = $this->asset(AssetStatus::Verified, bytes: 2000, kind: AssetKind::AudioMaster);

        DB::table('assets')->where('id', $stranger->id)->update(['kind' => 'HOLOGRAM']);

        $usage = $this->usage();

        $this->assertSame(3000, $usage['held']['bytes']);
        $this->assertSame(2000, $usage['by_kind']['HOLOGRAM']['bytes'] ?? null);
        $this->assertSame(1000, $usage['by_kind'][AssetKind::AudioMaster->value]['bytes']);
        $this->assertSame($usage['held']['bytes'], array_sum(array_column($usage['by_kind'], 'bytes')));

        // The known row is untouched by the stranger beside it.
        $this->assertSame(AssetKind::AudioMaster, $known->refresh()->kind);
    }

    #[Test]
    public function an_installation_that_cannot_be_asked_says_so_rather_than_zero(): void
    {
        // The distinction the whole panel turns on. An installation storing
        // nothing and one whose assets table would not answer are different
        // answers, and reporting the second as the first would tell an
        // operator their bill should be zero.
        Schema::disableForeignKeyConstraints();
        Schema::drop('assets');
        Schema::enableForeignKeyConstraints();

        $usage = $this->usage();

        $this->assertFalse($usage['measured']);
        $this->assertNull($usage['held']['bytes']);
        $this->assertNull($usage['trashed']['bytes']);
        $this->assertNull($usage['unsure']['bytes']);
        $this->assertSame([], $usage['by_kind']);
        $this->assertSame([], $usage['by_disk']);
    }

    // --------------------------------------------------- what it will not say

    #[Test]
    public function nothing_here_claims_a_capacity_or_a_percentage(): void
    {
        // A percentage needs a denominator, and this platform has none it can
        // honestly obtain: an object store publishes no quota it can read, and
        // inventing one from a free tier would be a guess with a progress bar
        // drawn around it.
        $this->asset(AssetStatus::Verified, bytes: 1000);

        $keys = array_keys($this->usage());

        foreach (['capacity', 'quota', 'limit', 'percent', 'percentage', 'free', 'remaining'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $keys,
                sprintf('[%s] is a number this platform cannot know.', $forbidden),
            );
        }
    }

    #[Test]
    public function an_empty_installation_reports_zero_and_says_it_measured(): void
    {
        $usage = $this->usage();

        $this->assertTrue($usage['measured']);
        $this->assertSame(0, $usage['held']['bytes']);
        $this->assertSame([], $usage['by_disk']);
    }

    // ----------------------------------------------------------- the screens

    #[Test]
    public function the_dashboard_says_how_much_is_being_kept(): void
    {
        $this->asset(AssetStatus::Verified, bytes: 4096);

        $usage = $this->actingAs($this->operator())
            ->get('/')
            ->viewData('page')['props']['snapshot']['storage_usage'];

        // Beside the asset count rather than instead of it: they answer
        // different questions, and only one of them turns into a bill.
        $this->assertSame(4096, $usage['held']['bytes']);
    }

    #[Test]
    public function the_settings_screen_says_it_too(): void
    {
        $this->asset(AssetStatus::Verified, bytes: 4096);

        $usage = $this->actingAs($this->operator())
            ->get('/settings')
            ->viewData('page')['props']['settings']['storage_usage'];

        $this->assertSame(4096, $usage['held']['bytes']);
    }

    // ---------------------------------------------------------- the fixtures

    /**
     * @return array<string, mixed>
     */
    private function usage(): array
    {
        return $this->app->make(StorageUsageQuery::class)->overview();
    }

    private function asset(
        AssetStatus $status,
        int $bytes,
        AssetKind $kind = AssetKind::AudioMaster,
        string $disk = 'local',
        bool $trashed = false,
    ): Asset {
        $asset = Asset::factory()->create([
            'kind' => $kind,
            'disk' => $disk,
            'byte_size' => $bytes,
            'status' => $status,
        ]);

        if ($trashed) {
            $asset->forceFill(['trashed_at' => now(), 'trash_reason' => 'OPERATOR_REQUEST'])->save();
        }

        return $asset;
    }

    private function operator(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }
}
