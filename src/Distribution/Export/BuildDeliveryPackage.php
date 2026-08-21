<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Export;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Storage\OriginalFilename;
use SaniTube\Distribution\Exceptions\DistributionException;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Exceptions\ReleasePackagingException;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Packaging\PackagedArtist;
use SaniTube\Releases\Packaging\PackagedContributor;
use SaniTube\Releases\Packaging\PackagedTrack as BoundaryTrack;
use SaniTube\Releases\Packaging\PackageRelease;
use SaniTube\Releases\Packaging\ReleasePackage;
use SaniTube\Storage\StorageManager;

/**
 * Rendering the boundary description as a sheet somebody can upload.
 *
 * **This class does not decide what crosses.** {@see PackageRelease} does, and
 * DIST-006 exists because the first version of this exporter forgot it: it
 * walked the Release aggregate itself — its own tracks query, its own
 * identifier lookup, its own "has this a master" check — which is precisely the
 * thing {@see ReleasePackage} was created to stop.
 *
 * That type's own docblock says why, and it applies to an exporter exactly as
 * it applies to an API adapter:
 *
 * > Before it, an adapter would have walked the Release aggregate itself …
 * > which means every adapter reaches differently, every adapter has its own
 * > chance to read a revoked identifier as current, and "what did we actually
 * > send" is answerable only by re-running whatever that adapter happened to do.
 *
 * Two descriptions of what a distributor is handed will disagree eventually,
 * and the one that disagrees is the one somebody acted on. So there is one, and
 * this renders it.
 *
 * **What this still looks up itself is the file, not the record.** A digest, a
 * byte count and a storage key are deliberately absent from the boundary type —
 * "no secret and no location is in here" — and an operator about to upload two
 * gigabytes needs all three. They are read from the asset the boundary type
 * names, which is a different question from what the release *is*.
 *
 * The columns are configuration. Every distributor's intake sheet differs, so
 * the headings and their order live in `config/distribution.php`, no
 * distributor is named in this code, and a heading pointing at a field
 * {@see ExportField} does not have is dropped rather than rendered as a column
 * of blanks.
 */
final readonly class BuildDeliveryPackage
{
    public function __construct(
        private Repository $config,
        private StorageManager $storage,
        private DeliveryPackageKeys $keys,
        private PackageRelease $packager,
    ) {}

    /**
     * @throws DistributionException|ReleasePackagingException when the release is not one this platform will hand over
     */
    public function handle(Release $release, ?DateTimeImmutable $now = null): DeliveryPackage
    {
        // **READY, and asked here rather than by the packager.** REL-004 uses
        // the packager to show a label what *would* cross while a release is
        // still being assembled, which is exactly why it does not require a
        // finished release. Handing one over does — a draft is still being
        // changed, and the next step here is a person uploading files by hand
        // rather than an API that might refuse.
        if ($release->status !== ReleaseStatus::Ready) {
            throw DistributionException::releaseNotReady();
        }

        // Everything else — validation, the cover, the tracks, every
        // identifier — is asked once, of the service that answers it for every
        // outbound channel. A refusal carries the same code an API delivery
        // would give.
        $boundary = $this->packager->handle($release);

        $headings = $this->headings();
        $fields = $this->fields();
        $releaseValues = $this->releaseValues($boundary);

        $masters = $this->mastersOf($boundary);

        $tracks = [];
        $warnings = [];

        foreach ($boundary->tracks as $track) {
            $master = $masters[$track->masterAssetUuid] ?? null;

            if (! $master instanceof Asset) {
                // The boundary type already refuses a track with no master, so
                // reaching here means the row it names is gone from under it.
                // Named rather than rendered as a blank cell: a package listing
                // a file that does not exist is one an operator finds out about
                // halfway through an upload.
                throw ReleasePackagingException::trackWithoutMaster([$track->uuid]);
            }

            if ($track->isrc === null) {
                // A warning, not a refusal: some distributors assign ISRCs on
                // intake, and refusing to export until every code exists would
                // make the platform unusable for the labels that rely on it.
                $warnings[] = sprintf('Track %d-%d has no ISRC.', $track->discNumber, $track->trackNumber);
            }

            $fileName = $this->fileName($track, $master);

            $values = $releaseValues + [
                ExportField::DiscNumber->value => (string) $track->discNumber,
                ExportField::TrackNumber->value => (string) $track->trackNumber,
                ExportField::TrackTitle->value => $track->title,
                ExportField::TrackVersion->value => (string) $track->versionTitle,
                ExportField::TrackArtist->value => $this->artists($track->artists),
                ExportField::TrackContributors->value => $this->contributors($track->contributors),
                ExportField::Isrc->value => (string) $track->isrc,
                ExportField::LanguageCode->value => $track->languageCode,
                ExportField::IsFocusTrack->value => $this->flag($track->isFocusTrack),
                ExportField::IsExplicit->value => $this->flag($track->isExplicit),
                ExportField::IsInstrumental->value => $this->flag($track->isInstrumental),
                ExportField::GenrePrimary->value => (string) $track->genrePrimary,
                ExportField::GenreSecondary->value => (string) $track->genreSecondary,
                ExportField::TrackPLine->value => (string) $track->pLine,
                ExportField::FileName->value => $fileName,
                ExportField::FileChecksum->value => (string) ($master->sha256 ?? ''),
                ExportField::FileBytes->value => (string) ($master->byte_size ?? ''),
                ExportField::DurationSeconds->value => $track->durationMs === null
                    ? ''
                    : (string) intdiv($track->durationMs, 1000),
            ];

            $columns = [];

            foreach ($fields as $heading => $field) {
                $columns[$heading] = $values[$field->value] ?? '';
            }

            $tracks[] = new PackagedTrack(
                discNumber: $track->discNumber,
                trackNumber: $track->trackNumber,
                trackUuid: $track->uuid,
                assetUuid: $track->masterAssetUuid,
                storageKey: $master->path,
                fileName: $fileName,
                checksum: $master->sha256,
                bytes: $master->byte_size,
                columns: $columns,
            );
        }

        if ($boundary->upc === null) {
            $warnings[] = 'The release has no UPC.';
        }

        $package = new DeliveryPackage(
            releaseUuid: $boundary->uuid,
            prefix: $this->keys->prefix($release),
            metadataKey: $this->keys->metadata($release),
            manifestKey: $this->keys->manifest($release),
            tracks: $tracks,
            warnings: $warnings,
            headings: $headings,
        );

        $this->write($package, ($now ?? new DateTimeImmutable)->format(DATE_ATOM));

        return $package;
    }

    /**
     * The stored masters the boundary description names, keyed by uuid.
     *
     * One query rather than one per track: a three-hundred-track compilation is
     * a legitimate release, and a lookup inside the loop is three hundred round
     * trips to describe a file list.
     *
     * @return array<string, Asset>
     */
    private function mastersOf(ReleasePackage $package): array
    {
        $uuids = array_map(
            static fn (BoundaryTrack $track): string => $track->masterAssetUuid,
            $package->tracks,
        );

        /** @var array<string, Asset> $masters */
        $masters = Asset::query()
            ->whereIn('uuid', array_values(array_unique($uuids)))
            ->get()
            ->keyBy('uuid')
            ->all();

        return $masters;
    }

    /**
     * Write the two files, overwriting whatever an earlier build left.
     *
     * Deterministic keys rather than a timestamped folder per run: a release
     * exported four times while its credits were being fixed should leave one
     * package, not four, and an operator opening the export folder should not
     * have to work out which of them is current.
     */
    private function write(DeliveryPackage $package, string $builtAt): void
    {
        $store = $this->storage->default();

        $store->put($package->metadataKey, $this->csv($package->rows()));
        $store->put(
            $package->manifestKey,
            (string) json_encode($package->manifest($builtAt), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows): string
    {
        $out = '';

        foreach ($rows as $row) {
            $cells = [];

            foreach ($row as $cell) {
                // Quoted unconditionally. A title containing a comma is
                // ordinary, and a sheet that only quotes when it thinks it has
                // to is a sheet that eventually decides wrongly.
                $cells[] = '"'.str_replace('"', '""', $cell).'"';
            }

            // CRLF: the line ending every spreadsheet application agrees on.
            $out .= implode(',', $cells)."\r\n";
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function releaseValues(ReleasePackage $package): array
    {
        return [
            ExportField::ReleaseTitle->value => $package->title,
            ExportField::ReleaseVersion->value => (string) $package->versionTitle,
            ExportField::ReleaseArtist->value => $this->artists($package->artists),
            ExportField::ReleaseType->value => $package->type,
            ExportField::Upc->value => (string) $package->upc,
            ExportField::LabelName->value => (string) $package->labelName,
            ExportField::CatalogueNumber->value => (string) $package->catalogueNumber,
            ExportField::ReleaseDate->value => (string) $package->releaseDate,
            ExportField::OriginalReleaseDate->value => (string) $package->originalReleaseDate,
            ExportField::PLine->value => (string) $package->pLine,
            ExportField::CLine->value => (string) $package->cLine,
        ];
    }

    /**
     * @param  list<PackagedArtist>  $artists
     */
    private function artists(array $artists): string
    {
        // In the order the release states, not alphabetically: on a
        // collaboration the order of the names is part of the credit.
        $ordered = $artists;

        usort($ordered, static fn (PackagedArtist $a, PackagedArtist $b): int => $a->position <=> $b->position);

        return implode(', ', array_map(
            static fn (PackagedArtist $artist): string => $artist->name,
            $ordered,
        ));
    }

    /**
     * @param  list<PackagedContributor>  $contributors
     */
    private function contributors(array $contributors): string
    {
        return implode('; ', array_map(
            static fn (PackagedContributor $contributor): string => sprintf(
                '%s (%s)',
                $contributor->name,
                $contributor->role,
            ),
            $contributors,
        ));
    }

    /**
     * A flag a spreadsheet will not reinterpret.
     *
     * `yes` and `no` rather than `true`/`false` or `1`/`0`: every intake form
     * wants a different one, an operator can find and replace a word, and a
     * bare `0` is the kind of cell a spreadsheet decides is a number.
     */
    private function flag(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    /**
     * What the master is called inside the package.
     *
     * A rendering of the running order and the title, sanitised the same way an
     * uploaded filename is. It decides nothing: the manifest carries the
     * track's uuid and the master's checksum beside it, so two tracks whose
     * titles reduce to the same string are still two recordings.
     */
    private function fileName(BoundaryTrack $track, Asset $master): string
    {
        $extension = OriginalFilename::extension($master->original_filename);

        $stem = OriginalFilename::sanitise(sprintf(
            '%02d-%02d %s',
            $track->discNumber,
            $track->trackNumber,
            $track->title,
        ));

        return $extension === null ? $stem : $stem.'.'.$extension;
    }

    /**
     * The configured columns, in order, dropping any heading that names a field
     * this platform cannot answer.
     *
     * @return array<string, ExportField>
     */
    private function fields(): array
    {
        /** @var array<mixed, mixed> $configured */
        $configured = (array) $this->config->get('distribution.export.columns', []);

        $fields = [];

        foreach ($configured as $heading => $field) {
            if (! is_string($heading) || ! is_string($field)) {
                continue;
            }

            $known = ExportField::tryFrom($field);

            if ($known === null) {
                continue;
            }

            $fields[$heading] = $known;
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    private function headings(): array
    {
        return array_keys($this->fields());
    }
}
