<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use SaniTube\Artists\Models\Artist;
use SaniTube\Assets\Models\Asset;
use SaniTube\Catalog\Enums\CompositionContributorRole;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Enums\TrackArtistRole;
use SaniTube\Catalog\Models\Composition;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\AssignExternalIdentifier;
use SaniTube\Contributors\Models\Contributor;
use SaniTube\Releases\Enums\ReleaseArtistRole;
use SaniTube\Releases\Enums\ReleaseType;
use SaniTube\Releases\Models\Release;

/**
 * A small but internally consistent catalogue, for looking at the model
 * without having 900 real tracks to hand.
 *
 * Every identifier here is syntactically valid and belongs to no real
 * registrant, and no distributor or DSP is named: seed data must never carry a
 * provider's vocabulary into the domain.
 */
final class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $assign = app(AssignExternalIdentifier::class);

        $solo = Artist::factory()->create(['name' => 'Lune Pâle', 'slug' => 'lune-pale']);
        $duo = Artist::factory()->group()->create(['name' => 'Nord Nord', 'slug' => 'nord-nord']);

        $writer = Contributor::factory()->create(['legal_name' => 'Camille Roussel']);
        $publisher = Contributor::factory()->organisation()->create(['legal_name' => 'Atelier Éditions']);

        $assign($writer, ExternalIdentifierType::Ipi, '00123456789', ExternalIdentifierSource::Manual);

        $compositions = collect(['Marée basse', 'Ville close', 'Rien à déclarer'])
            ->map(function (string $title) use ($writer, $publisher, $assign): Composition {
                $composition = Composition::factory()->registered()->create(['title' => $title]);

                $composition->contributors()->attach($writer->id, [
                    'role' => CompositionContributorRole::Composer->value,
                    'share' => 100,
                    'position' => 0,
                ]);

                $composition->contributors()->attach($publisher->id, [
                    'role' => CompositionContributorRole::Publisher->value,
                    'share' => 100,
                    'position' => 0,
                ]);

                $assign($composition, ExternalIdentifierType::Iswc, 'T'.random_int(1000000000, 9999999999));

                return $composition;
            });

        $tracks = collect(range(1, 5))->map(function (int $index) use ($compositions, $solo, $duo, $assign): Track {
            $track = Track::factory()->create([
                'title' => 'Piste '.$index,
                'composition_id' => $compositions[($index - 1) % $compositions->count()]->id,
                'master_asset_id' => Asset::factory()->master()->verified()->create()->id,
            ]);

            $track->artists()->attach($index % 2 === 0 ? $duo->id : $solo->id, [
                'role' => TrackArtistRole::Primary->value,
                'position' => 0,
            ]);

            // A collaboration: two primary artists on one recording, which the
            // model supports precisely because there is no single-artist
            // column to force a choice.
            if ($index === 3) {
                $track->artists()->attach($duo->id, [
                    'role' => TrackArtistRole::Primary->value,
                    'position' => 1,
                ]);
            }

            $track->refresh()->markReady();

            $assign(
                $track,
                ExternalIdentifierType::Isrc,
                sprintf('FRX99%02d%05d', 25, $index),
                ExternalIdentifierSource::Manual,
                isAuthoritative: true,
            );

            return $track->refresh();
        });

        $this->buildRelease('Marée basse', ReleaseType::Single, $solo, $tracks->take(1), $assign, '000000000017');
        $this->buildRelease('Nord Nord — EP', ReleaseType::Ep, $duo, $tracks->skip(1)->take(4), $assign, '000000000024');
    }

    /**
     * @param  Collection<int, Track>  $tracks
     */
    private function buildRelease(
        string $title,
        ReleaseType $type,
        Artist $artist,
        Collection $tracks,
        AssignExternalIdentifier $assign,
        string $upc,
    ): void {
        $release = Release::factory()->create([
            'title' => $title,
            'type' => $type,
            'cover_asset_id' => Asset::factory()->artwork()->verified()->create()->id,
        ]);

        $release->artists()->attach($artist->id, [
            'role' => ReleaseArtistRole::Primary->value,
            'position' => 0,
        ]);

        $number = 1;

        foreach ($tracks as $track) {
            $release->tracks()->attach($track->id, [
                'disc_number' => 1,
                'track_number' => $number,
                'is_focus_track' => $number === 1,
            ]);

            $number++;
        }

        $release->refresh()->markReady();

        $assign($release, ExternalIdentifierType::Upc, $upc, ExternalIdentifierSource::Manual, isAuthoritative: true);
    }
}
