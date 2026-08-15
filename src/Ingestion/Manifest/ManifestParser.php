<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Manifest;

use SaniTube\Catalog\Enums\ExternalIdentifierType;
use SaniTube\Catalog\Exceptions\ExternalIdentifierException;
use SaniTube\Catalog\Services\ExternalIdentifierNormaliser;
use SaniTube\Ingestion\Exceptions\IngestionException;
use SaniTube\Ingestion\Exceptions\ManifestException;
use SaniTube\Ingestion\Sources\Contracts\IngestionSourceReader;

/**
 * Reads an import manifest.
 *
 * CSV, because a manifest is written by whoever holds the catalogue today and
 * that is overwhelmingly a spreadsheet. Anything requiring a schema, a
 * toolchain or a validator would mean the file has to be produced by a
 * programmer, which defeats the point of accepting a manifest at all.
 *
 * Three properties are load-bearing:
 *
 *   - **A bad row is refused, not fatal.** Nine hundred lines with three
 *     mistakes must import eight hundred and ninety seven. The three come back
 *     as {@see ManifestRowError} with their line numbers.
 *   - **Identifiers go through the catalogue's own normaliser.** Not a second
 *     regex here. If ISRC validity were decided in two places, the import
 *     would eventually accept something assignment later refuses — and the
 *     operator would discover it after the bytes were already in.
 *   - **Nothing is silently dropped.** A column SaniTube has no field for is
 *     kept in `extra` and reported, rather than ignored. An operator who put
 *     `mood` in their spreadsheet is entitled to know it was not understood.
 *
 * What it does *not* do is decide what any of this means for the catalogue.
 * Every value except the reference is evidence; see {@see ManifestRow}.
 */
final readonly class ManifestParser
{
    /**
     * Header spellings accepted for each field.
     *
     * `filename` is deliberately absent from the reference aliases. A filename
     * is not an identity — everyone's file is called `master.wav` — and
     * accepting a column of them as the thing to fetch would encourage exactly
     * the mistake the ingestion key exists to prevent.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'reference' => ['reference', 'source_reference', 'file', 'path', 'object', 'object_key', 'key'],
        'title' => ['title', 'track_title', 'song', 'song_title'],
        'artist' => ['artist', 'artist_name', 'primary_artist', 'performer'],
        'release_title' => ['release', 'release_title', 'album', 'album_title'],
        'disc_number' => ['disc', 'disc_number', 'disc_no', 'disc_num'],
        'track_number' => ['track', 'track_number', 'track_no', 'track_num'],
        'language' => ['language', 'lang'],
        'isrc' => ['isrc'],
        'upc' => ['upc', 'barcode'],
        'legacy_provider' => ['legacy_provider', 'previous_distributor', 'distributor', 'provider'],
        'notes' => ['notes', 'note', 'comment', 'comments'],
    ];

    /** Delimiters sniffed from the header line, in order of preference. */
    private const DELIMITERS = [',', ';', "\t", '|'];

    public function __construct(private ExternalIdentifierNormaliser $identifiers) {}

    /**
     * @param  resource  $stream
     *
     * @throws ManifestException when the file as a whole cannot be used
     */
    public function parse($stream, ?IngestionSourceReader $reader = null): Manifest
    {
        $maxRows = max(1, (int) config('ingestion.manifest_max_rows', 5000));

        $header = $this->readHeader($stream);
        $delimiter = $header['delimiter'];
        $map = $header['map'];
        $unknown = $header['unknown'];

        /** @var array<string, ManifestRow> $rows */
        $rows = [];
        /** @var list<ManifestRowError> $errors */
        $errors = [];
        /** @var array<string, int> $referenceLines */
        $referenceLines = [];
        /** @var array<string, list<int>> $isrcLines */
        $isrcLines = [];

        $line = 1;
        $dataRows = 0;

        while (($record = fgetcsv($stream, 0, $delimiter, '"', '\\')) !== false) {
            $line++;

            if ($this->isBlank($record)) {
                continue;
            }

            $dataRows++;

            if ($dataRows > $maxRows) {
                throw ManifestException::tooManyRows($maxRows);
            }

            $values = $this->associate($record, $map);
            $reference = trim((string) ($values['reference'] ?? ''));

            if ($reference === '') {
                $errors[] = new ManifestRowError(
                    $line,
                    ManifestRowError::MISSING_REFERENCE,
                    'The row names no object to import.',
                );

                continue;
            }

            if (isset($referenceLines[$reference])) {
                // Both lines are refused, not just the second. Two rows
                // describing the same object with different metadata is a
                // question for whoever wrote the manifest, and answering it by
                // taking whichever came first is an arbitrary decision
                // presented as a result.
                $errors[] = new ManifestRowError(
                    $line,
                    ManifestRowError::DUPLICATE_REFERENCE,
                    sprintf('[%s] is also named on line %d.', $reference, $referenceLines[$reference]),
                    $reference,
                );

                if (isset($rows[$reference])) {
                    $errors[] = new ManifestRowError(
                        $referenceLines[$reference],
                        ManifestRowError::DUPLICATE_REFERENCE,
                        sprintf('[%s] is also named on line %d.', $reference, $line),
                        $reference,
                    );

                    unset($rows[$reference]);
                }

                continue;
            }

            $referenceLines[$reference] = $line;

            if ($reader instanceof IngestionSourceReader) {
                try {
                    $reader->assertAcceptable($reference);
                } catch (IngestionException $exception) {
                    $errors[] = new ManifestRowError(
                        $line,
                        ManifestRowError::UNACCEPTABLE_REFERENCE,
                        $exception->getMessage(),
                        $reference,
                    );

                    continue;
                }
            }

            $row = $this->buildRow($line, $reference, $values, $errors);

            if (! $row instanceof ManifestRow) {
                continue;
            }

            if ($row->isrc !== null) {
                $isrcLines[$row->isrc][] = $line;
            }

            $rows[$reference] = $row;
        }

        if ($dataRows === 0) {
            throw ManifestException::empty();
        }

        [$rows, $errors] = $this->refuseRepeatedIsrcs($rows, $errors, $isrcLines);

        return new Manifest($rows, $errors, $unknown);
    }

    /**
     * @param  resource  $stream
     * @return array{delimiter: string, map: array<int, string>, unknown: list<string>}
     */
    private function readHeader($stream): array
    {
        $first = fgets($stream);

        if ($first === false || trim($first) === '') {
            throw ManifestException::empty();
        }

        // Excel writes a UTF-8 BOM. Left in place it becomes part of the first
        // header's name, so the reference column silently stops being found.
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;

        $delimiter = $this->sniff($first);
        $header = str_getcsv(rtrim($first, "\r\n"), $delimiter, '"', '\\');

        /** @var array<int, string> $map */
        $map = [];
        /** @var list<string> $unknown */
        $unknown = [];
        /** @var array<string, int> $seen */
        $seen = [];

        foreach ($header as $index => $name) {
            $normalised = $this->normaliseHeader((string) $name);

            if ($normalised === '') {
                continue;
            }

            $field = $this->fieldFor($normalised);

            if ($field === null) {
                $unknown[] = $normalised;
                $map[$index] = '@'.$normalised;

                continue;
            }

            if (isset($seen[$field])) {
                throw ManifestException::duplicateColumn($field);
            }

            $seen[$field] = $index;
            $map[$index] = $field;
        }

        if (! isset($seen['reference'])) {
            throw ManifestException::missingReferenceColumn(self::COLUMNS['reference']);
        }

        return ['delimiter' => $delimiter, 'map' => $map, 'unknown' => $unknown];
    }

    /**
     * The delimiter the header line actually uses.
     *
     * Guessing is unavoidable: a European Excel writes semicolons and gives no
     * indication that it has. Guessing wrong is loud rather than subtle — a
     * one-column header has no reference column, which is refused by name.
     */
    private function sniff(string $headerLine): string
    {
        $best = ',';
        $bestCount = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = substr_count($headerLine, $delimiter);

            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function normaliseHeader(string $name): string
    {
        $name = strtolower(trim($name));
        $name = (string) preg_replace('/[\s\-]+/', '_', $name);

        return (string) preg_replace('/[^a-z0-9_]/', '', $name);
    }

    private function fieldFor(string $normalised): ?string
    {
        foreach (self::COLUMNS as $field => $aliases) {
            if (in_array($normalised, $aliases, true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param  list<string|null>  $record
     * @param  array<int, string>  $map
     * @return array<string, string>
     */
    private function associate(array $record, array $map): array
    {
        $values = [];

        foreach ($map as $index => $field) {
            $values[$field] = trim((string) ($record[$index] ?? ''));
        }

        // Columns past the end of the header. Kept rather than discarded: a
        // row wider than its header usually means an unescaped delimiter, and
        // seeing the surplus is how somebody works that out.
        foreach ($record as $index => $value) {
            if (! isset($map[$index]) && trim((string) $value) !== '') {
                $values['@column_'.($index + 1)] = trim((string) $value);
            }
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<ManifestRowError>  $errors
     */
    private function buildRow(int $line, string $reference, array $values, array &$errors): ?ManifestRow
    {
        $disc = $this->positiveInt($values['disc_number'] ?? '');
        $track = $this->positiveInt($values['track_number'] ?? '');

        if ($disc === false || $track === false) {
            $errors[] = new ManifestRowError(
                $line,
                ManifestRowError::MALFORMED_NUMBER,
                'Disc and track numbers must be whole numbers of 1 or more.',
                $reference,
            );

            return null;
        }

        $isrc = $this->identifier(ExternalIdentifierType::Isrc, $values['isrc'] ?? '');
        $upc = $this->identifier(ExternalIdentifierType::Upc, $values['upc'] ?? '');

        // A refused identifier refuses the row rather than being dropped. The
        // bytes would import perfectly well without it, and that is the
        // problem: the operator stated an ISRC, the platform would silently
        // decide it did not count, and the recording would sit in the
        // catalogue looking as though none was ever claimed for it.
        if ($isrc === false || $upc === false) {
            $errors[] = new ManifestRowError(
                $line,
                ManifestRowError::MALFORMED_IDENTIFIER,
                $isrc === false
                    ? sprintf('[%s] is not a valid ISRC.', $values['isrc'] ?? '')
                    : sprintf('[%s] is not a valid UPC.', $values['upc'] ?? ''),
                $reference,
            );

            return null;
        }

        /** @var array<string, string> $extra */
        $extra = [];

        foreach ($values as $field => $value) {
            if (str_starts_with($field, '@') && $value !== '') {
                $extra[substr($field, 1)] = $value;
            }
        }

        return new ManifestRow(
            line: $line,
            reference: $reference,
            title: $this->text($values['title'] ?? ''),
            artist: $this->text($values['artist'] ?? ''),
            releaseTitle: $this->text($values['release_title'] ?? ''),
            discNumber: $disc,
            trackNumber: $track,
            language: $this->text($values['language'] ?? ''),
            isrc: $isrc,
            upc: $upc,
            legacyProvider: $this->text($values['legacy_provider'] ?? ''),
            notes: $this->text($values['notes'] ?? ''),
            extra: $extra,
        );
    }

    /**
     * The same ISRC on two lines cannot be true: one ISRC identifies one
     * recording. Every line claiming it is refused.
     *
     * @param  array<string, ManifestRow>  $rows
     * @param  list<ManifestRowError>  $errors
     * @param  array<string, list<int>>  $isrcLines
     * @return array{0: array<string, ManifestRow>, 1: list<ManifestRowError>}
     */
    private function refuseRepeatedIsrcs(array $rows, array $errors, array $isrcLines): array
    {
        foreach ($isrcLines as $isrc => $lines) {
            if (count($lines) < 2) {
                continue;
            }

            foreach ($rows as $reference => $row) {
                if ($row->isrc !== (string) $isrc) {
                    continue;
                }

                $errors[] = new ManifestRowError(
                    $row->line,
                    ManifestRowError::DUPLICATE_ISRC,
                    sprintf(
                        'ISRC [%s] is claimed by lines %s. One ISRC identifies one recording.',
                        $isrc,
                        implode(', ', $lines),
                    ),
                    $reference,
                );

                unset($rows[$reference]);
            }
        }

        return [$rows, $errors];
    }

    /**
     * @return int|null|false false when the value was present but unusable
     */
    private function positiveInt(string $value): int|false|null
    {
        if (trim($value) === '') {
            return null;
        }

        if (preg_match('/^\d+$/', trim($value)) !== 1) {
            return false;
        }

        $number = (int) trim($value);

        return $number >= 1 ? $number : false;
    }

    /**
     * @return string|null|false false when the value was present but unusable
     */
    private function identifier(ExternalIdentifierType $type, string $value): string|false|null
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return $this->identifiers->normaliseValue($type, $value);
        } catch (ExternalIdentifierException) {
            return false;
        }
    }

    private function text(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<string|null>  $record
     */
    private function isBlank(array $record): bool
    {
        foreach ($record as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
