<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Export;

use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use SaniTube\Assets\Enums\AssetKind;
use SaniTube\Assets\Models\Asset;
use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Assets\Storage\OriginalFilename;
use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Distribution\Exceptions\DistributionException;
use SaniTube\Releases\Enums\ReleaseStatus;
use SaniTube\Releases\Models\Release;
use SaniTube\Releases\Models\ReleaseTrack;
use SaniTube\Storage\StorageManager;

/**
 * Building the package, from the catalogue and nothing else.
 *
 * Every value written comes from a column somebody entered or a measurement
 * something took. Nothing is derived to fill a gap: an unassigned ISRC is an
 * empty cell and a warning, not a plausible-looking code that a distributor
 * would then register as real.
 *
 * **Only a READY release.** The same rule submission uses, for the same reason:
 * a draft is still being assembled, and a package built from one is how a
 * half-finished release reaches a store — the more so here, where the next step
 * is a person uploading files by hand rather than an API that might refuse.
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
        private AssetObjectKeys $keys,
    ) {}

    /**
     * @throws DistributionException when the release is not in a state worth handing over
     */
    public function handle(Release $release, ?DateTimeImmutable $now = null): DeliveryPackage
    {
        if ($release->status !== ReleaseStatus::Ready) {
            throw DistributionException::releaseNotReady();
        }

        $entries = ReleaseTrack::query()
            ->where('release_id', $release->id)
            // Deterministic, and the order the sheet is read in. A running
            // order that changed between two exports of the same release would
            // be two different packages with no way to tell which was uploaded.
            ->orderBy('disc_number')
            ->orderBy('track_number')
            ->orderBy('id')
            ->get();

        if ($entries->isEmpty()) {
            throw DistributionException::releaseNotReady();
        }

        $headings = $this->headings();
        $fields = $this->fields();
        $releaseValues = $this->releaseValues($release);

        $tracks = [];
        $warnings = [];

        foreach ($entries as $entry) {
            $track = $entry->track;

            if (! $track instanceof Track) {
                throw DistributionException::missingIdentifier('track');
            }

            $master = $track->masterAsset;

            if (! $master instanceof Asset) {
                // Nothing to hand over. Distinct from a metadata gap, which is
                // a warning: a track with no master is not deliverable at all.
                throw DistributionException::missingIdentifier(
                    sprintf('master audio for track %s', $track->uuid),
                );
            }

            $isrc = $this->identifier($track, ExternalIdentifierType::Isrc);

            if ($isrc === null) {
                $warnings[] = sprintf('Track %d-%d has no ISRC.', $entry->disc_number, $entry->track_number);
            }

            $fileName = $this->fileName($entry, $track, $master);

            $values = $releaseValues + [
                ExportField::DiscNumber->value => (string) $entry->disc_number,
                ExportField::TrackNumber->value => (string) $entry->track_number,
                ExportField::TrackTitle->value => (string) $track->title,
                ExportField::TrackVersion->value => (string) ($track->version_title ?? ''),
                ExportField::TrackArtist->value => $this->artistsOf($track),
                ExportField::Isrc->value => $isrc ?? '',
                ExportField::LanguageCode->value => $track->contentLanguage()->code,
                ExportField::IsFocusTrack->value => $entry->is_focus_track ? 'yes' : 'no',
                ExportField::FileName->value => $fileName,
                ExportField::FileChecksum->value => (string) ($master->sha256 ?? ''),
                ExportField::FileBytes->value => (string) ($master->byte_size ?? ''),
                ExportField::DurationSeconds->value => $master->duration_ms === null
                    ? ''
                    : (string) intdiv($master->duration_ms, 1000),
            ];

            $columns = [];

            foreach ($fields as $heading => $field) {
                $columns[$heading] = $values[$field->value] ?? '';
            }

            $tracks[] = new PackagedTrack(
                discNumber: $entry->disc_number,
                trackNumber: $entry->track_number,
                trackUuid: $track->uuid,
                assetUuid: $master->uuid,
                storageKey: $master->path,
                fileName: $fileName,
                checksum: $master->sha256,
                bytes: $master->byte_size,
                columns: $columns,
            );
        }

        if ($this->identifier($release, ExternalIdentifierType::Upc) === null) {
            $warnings[] = 'The release has no UPC.';
        }

        $prefix = $this->prefixFor($release);

        $package = new DeliveryPackage(
            releaseUuid: $release->uuid,
            prefix: $prefix,
            metadataKey: $prefix.'/metadata.csv',
            manifestKey: $prefix.'/manifest.json',
            tracks: $tracks,
            warnings: $warnings,
            headings: $headings,
        );

        $this->write($package, ($now ?? new DateTimeImmutable)->format(DATE_ATOM));

        return $package;
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
    private function releaseValues(Release $release): array
    {
        return [
            ExportField::ReleaseTitle->value => (string) $release->title,
            ExportField::ReleaseVersion->value => (string) ($release->version_title ?? ''),
            ExportField::ReleaseArtist->value => $this->artistsOf($release),
            ExportField::ReleaseType->value => $release->type->value,
            ExportField::Upc->value => $this->identifier($release, ExternalIdentifierType::Upc) ?? '',
            ExportField::LabelName->value => (string) ($release->label_name ?? ''),
            ExportField::CatalogueNumber->value => (string) ($release->catalogue_number ?? ''),
            ExportField::ReleaseDate->value => $release->release_date?->toDateString() ?? '',
            ExportField::OriginalReleaseDate->value => $release->original_release_date?->toDateString() ?? '',
            ExportField::PLine->value => (string) ($release->p_line ?? ''),
            ExportField::CLine->value => (string) ($release->c_line ?? ''),
        ];
    }

    /**
     * The active identifier of a type, or nothing.
     *
     * **Active**, so a revoked ISRC is never handed to a distributor as though
     * it still stood — the one place where presenting a withdrawn code would
     * put it back into circulation.
     */
    private function identifier(Track|Release $subject, ExternalIdentifierType $type): ?string
    {
        $identifier = $subject->externalIdentifiers()
            ->where('type', $type->value)
            ->whereNotNull('active_marker')
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->first();

        return $identifier instanceof ExternalIdentifier ? $identifier->value : null;
    }

    private function artistsOf(Track|Release $subject): string
    {
        $names = $subject->primaryArtists()
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return implode(', ', array_map(static fn (mixed $name): string => (string) $name, $names));
    }

    /**
     * What the master is called inside the package.
     *
     * A rendering of the running order and the title, sanitised the same way an
     * uploaded filename is. It decides nothing: the manifest carries the
     * track's uuid and the master's checksum beside it, so two tracks whose
     * titles reduce to the same string are still two recordings.
     */
    private function fileName(ReleaseTrack $entry, Track $track, Asset $master): string
    {
        $extension = OriginalFilename::extension($master->original_filename);

        $stem = OriginalFilename::sanitise(sprintf(
            '%02d-%02d %s',
            $entry->disc_number,
            $entry->track_number,
            $track->title,
        ));

        return $extension === null ? $stem : $stem.'.'.$extension;
    }

    /**
     * Where a package is written.
     *
     * **Not configurable, deliberately.** It is the prefix the platform already
     * owns for distribution output, which means the importer already refuses to
     * point "import everything under X" at it. A settable prefix would be a way
     * to put the platform's own output somewhere a later import would read it
     * back in as new material — and each pass would produce another generation
     * of duplicates.
     */
    private function prefixFor(Release $release): string
    {
        return $this->keys->prefixFor(AssetKind::DistributionExport).'/'.$release->uuid;
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
