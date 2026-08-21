<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionNamedType;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Enums\AssetStatus;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\Track;
use SaniTube\Distribution\Contracts\Distributor;
use SaniTube\Distribution\DistributorManager;
use SaniTube\Distribution\Enums\DistributionDeliveryStatus;
use SaniTube\Distribution\Services\SubmitDelivery;
use SaniTube\Distribution\Testing\FakeDistributor;
use SaniTube\Localization\ContentLanguage;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Packaging\ReleasePackage;
use SaniTube\Releases\Services\ReleaseBuilder;
use Tests\Support\Distribution\RecordingDistributor;
use Tests\TestCase;

/**
 * DIST-007. A distributor is handed a package, never the catalogue.
 *
 * `ReleasePackage` exists to be the one description of what crosses to a
 * distributor. DIST-004 wrote an exporter that walked the `Release` aggregate
 * instead — tracks, credits, identifiers, assets — and DIST-006 had to rewrite
 * it to render the package. The submission path still took the aggregate, so
 * the first real adapter would have repeated exactly that, and repeated it
 * differently from the next one: its own chance to read a revoked identifier as
 * current, its own answer to "what did we actually send".
 *
 * Two claims, and they need different kinds of test.
 *
 *   - **The production path assembles a package and submits that.** Asserted by
 *     walking it, with a distributor that records what it was handed.
 *   - **No adapter can reach around it.** Asserted by reading the source of
 *     every class implementing the contract, because a passing integration test
 *     proves what today's adapters do, not what tomorrow's may.
 *
 * The change caught something on its first run. `DistributionTest`'s fixture
 * built a track with `TrackStatus::Ready` written directly and no master audio
 * — the shape `TrackFactory`'s own comment calls impossible — and the old
 * submission path accepted it, because the adapter was handed the aggregate and
 * never looked. Packaging refuses it as `TRACK_WITHOUT_MASTER`: there is
 * nothing to deliver for such a track.
 */
final class PackageBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The classes a delivery may not read for its content.
     *
     * Not a stylistic list. Each of these is a place the catalogue's own shape
     * lives, and an adapter that reads one is deciding what a delivery contains
     * for a second time.
     *
     * @var list<string>
     */
    private const CATALOGUE = [
        'SaniTube\Releases\Models\Release',
        'SaniTube\Releases\Models\ReleaseTrack',
        'SaniTube\Catalog\Models\Track',
        'SaniTube\Catalog\Models\Contributor',
        'SaniTube\Catalog\Models\ExternalIdentifier',
        'SaniTube\Assets\Models\Asset',
    ];

    #[Test]
    public function the_contract_asks_for_a_package_and_never_the_aggregate(): void
    {
        $checked = 0;

        foreach ((new ReflectionClass(Distributor::class))->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $checked++;

                $this->assertNotContains(
                    $type->getName(),
                    self::CATALOGUE,
                    sprintf(
                        'Distributor::%s() takes %s. An adapter given the aggregate walks it, and every '
                            .'adapter walks it differently — which is the defect ReleasePackage was created '
                            .'to prevent.',
                        $method->getName(),
                        $type->getName(),
                    ),
                );
            }
        }

        $this->assertGreaterThan(2, $checked, 'The contract was read as taking almost no objects, so it was not read.');
    }

    #[Test]
    public function no_distributor_implementation_reads_the_catalogue_for_delivery_content(): void
    {
        $implementations = $this->implementations();

        // A scan that found no adapters would pass every assertion it does not
        // make.
        $this->assertGreaterThan(1, count($implementations), 'No Distributor implementations were found.');

        foreach ($implementations as $path => $source) {
            foreach (self::CATALOGUE as $class) {
                $this->assertStringNotContainsString(
                    'use '.$class.';',
                    $source,
                    sprintf(
                        '[%s] imports %s. An adapter may hold its own configuration and its own transport — '
                            .'an endpoint, a credential, a wire format — but its delivery content comes from '
                            .'the ReleasePackage it is handed.',
                        $path,
                        $class,
                    ),
                );
            }
        }
    }

    /**
     * The whole path, walked: a release becomes a package, the package is what
     * the distributor receives, and what it received is what the catalogue says.
     */
    #[Test]
    public function the_production_path_submits_the_package_it_assembled(): void
    {
        $distributor = new RecordingDistributor(new FakeDistributor('recording', sandbox: false));

        config(['distribution.default' => 'recording']);
        $this->app->forgetInstance(DistributorManager::class);
        $this->app->make(DistributorManager::class)->register('recording', $distributor);

        $release = $this->deliverableRelease();

        $delivery = $this->app->make(SubmitDelivery::class)->handle($release, 'recording');

        $this->assertSame(DistributionDeliveryStatus::Submitted, $delivery->status);

        // Both calls, and the same package object for each: assembling it twice
        // would be two chances to assemble it differently, and "what did we
        // send" has to have one answer.
        $this->assertInstanceOf(ReleasePackage::class, $distributor->prepared);
        $this->assertInstanceOf(ReleasePackage::class, $distributor->submitted);
        $this->assertSame($distributor->prepared, $distributor->submitted);

        // And it describes this release rather than something the adapter went
        // and fetched.
        $this->assertSame($release->uuid, $distributor->submitted->uuid);
        $this->assertSame($release->title, $distributor->submitted->title);
        $this->assertSame(1, $distributor->submitted->trackCount());
    }

    // -------------------------------------------------------------- fixtures

    /**
     * A release that can genuinely be delivered.
     *
     * `Track::factory()->ready()` and not a forced status: a track written
     * straight to READY has no master audio, which packaging refuses because
     * there is nothing to deliver for it. That is the shape this ticket found
     * in `DistributionTest`'s own fixture.
     */
    private function deliverableRelease(): Release
    {
        $builder = $this->app->make(ReleaseBuilder::class);

        $release = $builder->createDraft('Night Bus');

        $track = Track::factory()->ready()->create([
            'title' => 'Night Bus',
            'language_code' => ContentLanguage::UNKNOWN,
            'is_instrumental' => false,
        ]);

        $track->externalIdentifiers()->create([
            'type' => ExternalIdentifierType::Isrc,
            'value' => 'FRZ859900001',
            'source' => ExternalIdentifierSource::Manual,
            'assigned_at' => now(),
        ]);

        $release = $builder->addTrack($release, $track);

        $builder->setArtists($release->refresh(), [
            ['artist' => Artist::factory()->create(), 'role' => ReleaseArtistRole::Primary],
        ]);

        $bytes = 'artwork bytes';

        $builder->setCover($release->refresh(), Asset::query()->create([
            'kind' => AssetKind::Artwork,
            'status' => AssetStatus::Verified,
            'disk' => 'memory',
            'path' => 'artwork/'.bin2hex(random_bytes(4)).'.jpg',
            'original_filename' => 'cover.jpg',
            'sha256' => hash('sha256', $bytes),
            'byte_size' => strlen($bytes),
            'mime_type' => 'image/jpeg',
        ]));

        $release = $builder->updateDetails($release->refresh(), [
            'release_date' => now()->addMonth()->toDateString(),
        ]);

        $release->externalIdentifiers()->create([
            'type' => ExternalIdentifierType::Upc,
            'value' => '0885000000001',
            'source' => ExternalIdentifierSource::Manual,
            'assigned_at' => now(),
        ]);

        $release->markReady();

        return $release->refresh();
    }

    /**
     * Every class implementing the contract, as source.
     *
     * @return array<string, string>
     */
    private function implementations(): array
    {
        $found = [];

        /** @var list<string> $files */
        $files = [];
        $this->collect(base_path('src'), $files);

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/\bimplements\s+[^{]*\bDistributor\b/', $source) === 1) {
                $found[ltrim(str_replace(base_path(), '', $file), '/')] = $source;
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $into
     */
    private function collect(string $directory, array &$into): void
    {
        /** @var list<string> $entries */
        $entries = glob(rtrim($directory, '/').'/*') ?: [];

        foreach ($entries as $entry) {
            if (is_dir($entry)) {
                $this->collect($entry, $into);

                continue;
            }

            if (str_ends_with($entry, '.php')) {
                $into[] = $entry;
            }
        }
    }
}
