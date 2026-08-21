<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Enums\TrackStatus;
use SaniTube\Catalog\Models\Track;
use SaniTube\Distribution\Exceptions\DistributionException;
use SaniTube\Distribution\Export\BuildDeliveryPackage;
use SaniTube\Distribution\Export\DeliveryPackage;
use SaniTube\Distribution\Export\PackagedTrack;
use SaniTube\Distribution\Models\DistributionDelivery;
use SaniTube\Ingestion\Enums\IngestionSource;
use SaniTube\Ingestion\Exceptions\IngestionException;
use SaniTube\Ingestion\Sources\SourceReaderFactory;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Services\ReleaseBuilder;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * DIST-004. Getting a release out of the platform without an API.
 *
 * **The largest gap the platform had.** A catalogue could be imported,
 * reviewed, credited, validated and marked READY — and then it stopped. The
 * only distributors that existed were `none` and a fake, so a real label using
 * SaniTube had no way to get audio and metadata *out*, in any form.
 *
 * Almost no distributor publishes an API, ADR-0018 forbids inventing one from a
 * reverse-engineered wrapper, and every one of them has a web portal. So the
 * delivery path that works everywhere is a person with a spreadsheet and a set
 * of files, and this builds them.
 *
 * Three claims carry these tests:
 *
 *   - **It is not a submission.** Nothing is sent, nothing becomes
 *     irreversible, and no delivery row is written. Conflating a repeatable
 *     export with the one act in this platform that cannot be undone would
 *     weaken the three guards that protect that act.
 *   - **Nothing is invented to fill a cell.** An unassigned ISRC is an empty
 *     cell and a warning, never a plausible-looking code that a distributor
 *     would then register as real.
 *   - **A filename is a rendering, never an identity.** The manifest carries
 *     the track's uuid and the master's checksum beside it, so two tracks whose
 *     titles reduce to the same string are still two recordings.
 */
final class ManualDeliveryPackageTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);
    }

    // ------------------------------------------------------------ the sheet

    #[Test]
    public function a_ready_release_produces_a_metadata_sheet_and_a_manifest(): void
    {
        $release = $this->readyRelease();

        $package = $this->build($release);

        $this->assertTrue($this->store->exists($package->metadataKey));
        $this->assertTrue($this->store->exists($package->manifestKey));

        $csv = $this->store->get($package->metadataKey);

        $this->assertStringContainsString('"Track Title"', $csv);
        $this->assertStringContainsString('"Night Bus"', $csv);
        $this->assertStringContainsString('"FRZ859900001"', $csv);
    }

    /**
     * The shipped default sheet, end to end.
     *
     * The configured defaults are what an operator gets before they have edited
     * anything, and a test that only ever exercises a two-column override would
     * never notice that the twenty-three shipped headings had stopped
     * resolving.
     */
    #[Test]
    public function every_shipped_column_resolves_to_a_field_and_a_value(): void
    {
        $release = $this->readyRelease();
        $package = $this->build($release);

        $this->assertCount(
            count((array) config('distribution.export.columns')),
            $package->headings,
            'A shipped heading names a field the exporter dropped.',
        );

        $row = $package->tracks[0]->columns;

        // Every heading has a cell, and the ones that cannot be empty on a
        // complete release are not.
        foreach ($package->headings as $heading) {
            $this->assertArrayHasKey($heading, $row);
        }

        $this->assertSame('Night Bus', $row['Track Title']);
        $this->assertSame('1', $row['Track']);
        $this->assertSame('FRZ859900001', $row['ISRC']);
        $this->assertSame('0885000000001', $row['UPC']);
        $this->assertSame(hash('sha256', 'audio'), $row['File SHA-256']);
        $this->assertSame('214', $row['Duration (s)']);
        $this->assertSame('01-01 Night Bus.wav', $row['File Name']);
    }

    /**
     * The columns are configuration, because every intake form is different.
     * No distributor is named anywhere in the export code.
     */
    #[Test]
    public function the_columns_and_their_order_come_from_configuration(): void
    {
        config(['distribution.export.columns' => [
            'Song' => 'track_title',
            'Code' => 'isrc',
        ]]);

        $package = $this->build($this->readyRelease());

        $this->assertSame(['Song', 'Code'], $package->headings);
        $this->assertStringStartsWith("\"Song\",\"Code\"\r\n", $this->store->get($package->metadataKey));
    }

    /**
     * A heading naming something SaniTube cannot answer is dropped.
     *
     * A column of blanks reads, to whoever uploads the sheet, as data the
     * catalogue does not hold — which is a worse answer than not offering the
     * column.
     */
    #[Test]
    public function a_heading_naming_a_field_that_does_not_exist_is_dropped(): void
    {
        config(['distribution.export.columns' => [
            'Song' => 'track_title',
            'Mood' => 'vibe',
            'Payout Rate' => 'royalty_percentage',
        ]]);

        $package = $this->build($this->readyRelease());

        $this->assertSame(['Song'], $package->headings);
    }

    #[Test]
    public function a_title_containing_a_comma_or_a_quote_survives_the_sheet(): void
    {
        $release = $this->readyRelease(title: 'Night Bus, "Reprise"');

        $csv = $this->store->get($this->build($release)->metadataKey);

        // Quoted unconditionally, and inner quotes doubled. A sheet that only
        // quotes when it thinks it has to is one that eventually decides wrong.
        $this->assertStringContainsString('"Night Bus, ""Reprise"""', $csv);
    }

    // ---------------------------------------------------- nothing invented

    #[Test]
    public function a_missing_isrc_is_an_empty_cell_and_a_warning_rather_than_a_refusal(): void
    {
        $package = $this->build($this->readyRelease(withIsrc: false));

        $this->assertNotSame([], $package->warnings);
        $this->assertStringContainsString('ISRC', implode(' ', $package->warnings));

        // And nothing that looks like one reached the sheet.
        $this->assertDoesNotMatchRegularExpression(
            '/[A-Z]{2}[A-Z0-9]{3}\d{7}/',
            $this->store->get($package->metadataKey),
            'Something that looks like an ISRC was written for a track that has none.',
        );
    }

    #[Test]
    public function a_missing_upc_is_named_in_the_warnings(): void
    {
        $package = $this->build($this->readyRelease(withUpc: false));

        $this->assertStringContainsString('UPC', implode(' ', $package->warnings));
    }

    /**
     * A revoked identifier is not handed to a distributor as though it stood.
     *
     * The one place where presenting a withdrawn code would put it back into
     * circulation: a portal reads the sheet and registers what it finds.
     */
    #[Test]
    public function a_revoked_isrc_is_not_exported(): void
    {
        $release = $this->readyRelease();

        $track = $release->tracks()->firstOrFail();
        $track->externalIdentifiers()->update(['active_marker' => null]);

        $package = $this->build($release);

        $this->assertStringNotContainsString('FRZ859900001', $this->store->get($package->metadataKey));
        $this->assertStringContainsString('ISRC', implode(' ', $package->warnings));
    }

    // ------------------------------------------------- filename is a label

    #[Test]
    public function the_manifest_carries_the_uuid_and_the_checksum_beside_the_filename(): void
    {
        $release = $this->readyRelease();
        $package = $this->build($release);

        $track = $package->tracks[0];

        $this->assertSame('01-01 Night Bus.wav', $track->fileName);

        // The two facts a filename cannot carry.
        $this->assertSame($release->tracks()->firstOrFail()->uuid, $track->trackUuid);
        $this->assertNotNull($track->checksum);

        $manifest = json_decode($this->store->get($package->manifestKey), true);

        $this->assertIsArray($manifest);
        $this->assertSame($release->uuid, $manifest['release_uuid']);
    }

    /**
     * No signed URL is written into the package.
     *
     * A link is a capability that expires. Writing one into a file that lives
     * in a bucket leaves a working credential lying around until it does not
     * work, which is the worst of both.
     */
    #[Test]
    public function the_manifest_contains_no_link_of_any_kind(): void
    {
        $package = $this->build($this->readyRelease());

        $manifest = $this->store->get($package->manifestKey);

        $this->assertStringNotContainsString('http://', $manifest);
        $this->assertStringNotContainsString('https://', $manifest);
        $this->assertStringNotContainsString('signature', $manifest);
    }

    // ----------------------------------------------------------- refusals

    #[Test]
    public function a_draft_release_is_refused(): void
    {
        $release = $this->completeDraft();

        try {
            $this->build($release);
            $this->fail('A draft was packaged.');
        } catch (DistributionException $exception) {
            $this->assertSame('RELEASE_NOT_READY', $exception->reason);
        }

        $this->assertSame([], $this->store->files('exports'));
    }

    /**
     * The running order *is* the deliverable.
     *
     * A portal reads the sheet top to bottom and numbers the release from it. A
     * package whose rows came out in insertion order, or in whatever order the
     * database felt like, would deliver an album with its tracks shuffled — and
     * nothing downstream would notice, because every row is individually
     * correct.
     */
    #[Test]
    public function the_sheet_is_in_running_order_whatever_order_the_tracks_were_added_in(): void
    {
        // Assembled while still a draft, because a READY release refuses
        // structural change — which is itself the right behaviour and the
        // reason this has to be set up before the release is closed.
        $release = $this->completeDraft();

        // The first track added moves out of first place, so insertion order
        // and running order genuinely disagree. Moved before the others are
        // added, because (release, disc, track) is unique — which is the index
        // that makes a running order a running order.
        $release->trackEntries()
            ->where('track_id', $release->tracks()->firstOrFail()->id)
            ->update(['disc_number' => 1, 'track_number' => 2]);

        $this->addTrackAt($release, 'Overture', disc: 1, number: 1);
        $this->addTrackAt($release, 'Coda', disc: 2, number: 1);

        $release->refresh()->markReady();

        $package = $this->build($release->refresh());

        $this->assertSame(
            ['Overture', 'Night Bus', 'Coda'],
            array_map(
                static fn (PackagedTrack $track): string => $track->columns['Track Title'],
                $package->tracks,
            ),
        );
    }

    /**
     * A track with no master is not deliverable at all.
     *
     * Distinct from a metadata gap, which is a warning. A release can be marked
     * READY and have a master detached afterwards, and a package listing a file
     * that does not exist is one an operator discovers halfway through an
     * upload.
     */
    #[Test]
    public function a_track_whose_master_has_gone_is_a_refusal_rather_than_a_blank_row(): void
    {
        $release = $this->readyRelease();

        $release->tracks()->firstOrFail()->forceFill(['master_asset_id' => null])->save();

        try {
            $this->build($release->refresh());
            $this->fail('A release with no audio was packaged.');
        } catch (DistributionException $exception) {
            $this->assertSame('MISSING_IDENTIFIER', $exception->reason);
        }
    }

    // ------------------------------------------------- not a submission

    #[Test]
    public function building_a_package_delivers_nothing(): void
    {
        $release = $this->readyRelease();

        $this->build($release);
        $this->build($release);

        // Not one delivery row, not one attempt. Building a package is
        // repeatable; submitting is the act that is not.
        $this->assertSame(0, DistributionDelivery::query()->count());
    }

    #[Test]
    public function rebuilding_leaves_one_package_rather_than_a_pile(): void
    {
        $release = $this->readyRelease();

        $first = $this->build($release);
        $this->build($release);

        $files = $this->store->files($first->prefix);

        sort($files);

        $this->assertSame([$first->manifestKey, $first->metadataKey], $files);
    }

    // -------------------------------------------- the platform's own output

    /**
     * A package cannot be imported back in.
     *
     * "Import everything under X" pointed at the export folder would read the
     * platform's own output as new material, and each pass would produce
     * another generation of duplicates. The prefix is therefore not
     * configurable: it is the one the platform already owns and the importer
     * already refuses.
     */
    #[Test]
    public function the_export_prefix_is_one_the_importer_refuses(): void
    {
        $package = $this->build($this->readyRelease());

        $reader = $this->app->make(SourceReaderFactory::class)->for(IngestionSource::CloudImport, null);

        $this->expectException(IngestionException::class);

        $reader->assertAcceptable($package->prefix);
    }

    #[Test]
    public function the_package_is_written_under_the_prefix_the_platform_owns_for_distribution_output(): void
    {
        $expected = $this->app->make(AssetObjectKeys::class)->prefixFor(AssetKind::DistributionExport);

        $this->assertStringStartsWith($expected.'/', $this->build($this->readyRelease())->prefix);
    }

    // ---------------------------------------------------------- the command

    #[Test]
    public function the_command_builds_the_package_and_says_nothing_was_submitted(): void
    {
        $release = $this->readyRelease();

        $this->artisan('sanitube:distribution:export', ['release' => $release->uuid])
            ->expectsOutputToContain('Nothing has been submitted')
            ->assertSuccessful();

        $this->assertSame(0, DistributionDelivery::query()->count());
    }

    /**
     * The signature *is* the permission to read a master, and a terminal is
     * scrollback, a screen share and a support thread.
     */
    #[Test]
    public function the_command_prints_no_link_unless_one_is_asked_for(): void
    {
        $release = $this->readyRelease();

        $this->artisan('sanitube:distribution:export', ['release' => $release->uuid])
            ->doesntExpectOutputToContain('signature=')
            ->doesntExpectOutputToContain('expires=')
            ->assertSuccessful();
    }

    #[Test]
    public function an_unknown_release_is_a_refusal_rather_than_a_crash(): void
    {
        $this->artisan('sanitube:distribution:export', ['release' => 'not-a-release'])->assertFailed();
    }

    // ------------------------------------------------------------ fixtures

    private function build(Release $release): DeliveryPackage
    {
        return $this->app->make(BuildDeliveryPackage::class)->handle($release);
    }

    private function addTrackAt(Release $release, string $title, int $disc, int $number): void
    {
        $master = Asset::query()->create([
            'kind' => AssetKind::AudioMaster,
            'status' => AssetStatus::Verified,
            'disk' => 'memory',
            'path' => 'masters/'.bin2hex(random_bytes(4)).'/original.wav',
            'original_filename' => 'take.wav',
            'sha256' => hash('sha256', $title),
            'byte_size' => 5,
            'mime_type' => 'audio/wav',
        ]);

        $track = Track::factory()->create([
            'title' => $title,
            'status' => TrackStatus::Ready,
            'language_code' => ContentLanguage::UNKNOWN,
            'is_instrumental' => false,
            'master_asset_id' => $master->id,
        ]);

        $track->artists()->attach(Artist::factory()->create(), ['role' => 'PRIMARY']);

        $this->builder()->addTrack($release, $track);

        $release->trackEntries()
            ->where('track_id', $track->id)
            ->update(['disc_number' => $disc, 'track_number' => $number]);
    }

    private function builder(): ReleaseBuilder
    {
        return $this->app->make(ReleaseBuilder::class);
    }

    private function completeDraft(bool $withIsrc = true, string $title = 'Night Bus'): Release
    {
        $release = $this->builder()->createDraft('Night Bus');

        $master = Asset::query()->create([
            'kind' => AssetKind::AudioMaster,
            'status' => AssetStatus::Verified,
            'disk' => 'memory',
            'path' => 'masters/'.bin2hex(random_bytes(4)).'/original.wav',
            'original_filename' => 'take-3-final-FINAL.wav',
            'sha256' => hash('sha256', 'audio'),
            'byte_size' => 5,
            'duration_ms' => 214_000,
            'mime_type' => 'audio/wav',
        ]);

        $track = Track::factory()->create([
            'title' => $title,
            'status' => TrackStatus::Ready,
            'language_code' => ContentLanguage::UNKNOWN,
            'is_instrumental' => false,
            'master_asset_id' => $master->id,
        ]);

        if ($withIsrc) {
            $track->externalIdentifiers()->create([
                'type' => ExternalIdentifierType::Isrc,
                'value' => 'FRZ859900001',
                'source' => ExternalIdentifierSource::Manual,
                'assigned_at' => now(),
                'active_marker' => 1,
            ]);
        }

        $track->artists()->attach(Artist::factory()->create(), ['role' => 'PRIMARY']);

        $release = $this->builder()->addTrack($release, $track);

        $this->builder()->setArtists($release->refresh(), [
            ['artist' => Artist::factory()->create(), 'role' => ReleaseArtistRole::Primary],
        ]);

        $bytes = 'artwork bytes';

        $this->builder()->setCover($release->refresh(), Asset::query()->create([
            'kind' => AssetKind::Artwork,
            'status' => AssetStatus::Verified,
            'disk' => 'memory',
            'path' => 'artwork/'.bin2hex(random_bytes(4)).'.jpg',
            'original_filename' => 'cover.jpg',
            'sha256' => hash('sha256', $bytes),
            'byte_size' => strlen($bytes),
            'mime_type' => 'image/jpeg',
        ]));

        return $this->builder()->updateDetails($release->refresh(), [
            'release_date' => now()->addMonth()->toDateString(),
        ]);
    }

    private function readyRelease(bool $withIsrc = true, bool $withUpc = true, string $title = 'Night Bus'): Release
    {
        $release = $this->completeDraft($withIsrc, $title);

        if ($withUpc) {
            $release->externalIdentifiers()->create([
                'type' => ExternalIdentifierType::Upc,
                'value' => '0885000000001',
                'source' => ExternalIdentifierSource::Manual,
                'assigned_at' => now(),
                'active_marker' => 1,
            ]);
        }

        $release->markReady();

        return $release->refresh();
    }
}
