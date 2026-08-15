<?php

declare(strict_types=1);

namespace SaniTube\Ingestion\Manifest;

/**
 * One line of an import manifest, after parsing.
 *
 * Everything on it except `reference` is **evidence**: what the operator says
 * about a file, not what the platform knows about it. A manifest is typically
 * exported from whatever held the catalogue before — a spreadsheet, a legacy
 * distributor's back office, somebody's memory — and any of those can be
 * years out of date. So none of these values is ever written into the
 * catalogue by the import itself. They travel to the TrackCandidate, where a
 * reviewer sees them beside what the file actually contains and decides.
 *
 * `reference` is the exception, and it is not metadata at all: it is the
 * object key the bytes come from, which is what makes the row actionable. A
 * row without one describes nothing importable.
 *
 * Identifiers are already normalised by the parser (via the catalogue's own
 * normaliser, not a second copy of those rules), so `FR-Z03-14-00001` and
 * `frz031400001` arrive here as the same string. A row whose identifier could
 * not be normalised never becomes a ManifestRow — it becomes a
 * {@see ManifestRowError}.
 */
final readonly class ManifestRow
{
    /**
     * @param  array<string, string>  $extra  columns the manifest carried that SaniTube has no field for
     */
    public function __construct(
        public int $line,
        public string $reference,
        public ?string $title = null,
        public ?string $artist = null,
        public ?string $releaseTitle = null,
        public ?int $discNumber = null,
        public ?int $trackNumber = null,
        public ?string $language = null,
        public ?string $isrc = null,
        public ?string $upc = null,
        public ?string $legacyProvider = null,
        public ?string $notes = null,
        public array $extra = [],
    ) {}

    /**
     * The row as it is recorded against the item and later the candidate.
     *
     * Absent fields are omitted rather than stored as null. A manifest that
     * said nothing about the language and a manifest that said the language
     * was blank are the same fact — that nobody stated one — and storing an
     * explicit null invites a later reader to treat it as a stated absence.
     *
     * @return array<string, mixed>
     */
    public function toEvidence(): array
    {
        $evidence = array_filter([
            'line' => $this->line,
            'reference' => $this->reference,
            'title' => $this->title,
            'artist' => $this->artist,
            'release_title' => $this->releaseTitle,
            'disc_number' => $this->discNumber,
            'track_number' => $this->trackNumber,
            'language' => $this->language,
            'isrc' => $this->isrc,
            'upc' => $this->upc,
            'legacy_provider' => $this->legacyProvider,
            'notes' => $this->notes,
        ], static fn (mixed $value): bool => $value !== null);

        if ($this->extra !== []) {
            $evidence['extra'] = $this->extra;
        }

        return $evidence;
    }

    /**
     * The row back from what was recorded against the item.
     *
     * The queue worker that imports a file runs minutes or hours after the
     * manifest was read, in another process, quite possibly after a restart.
     * It never sees the CSV — it sees this. The round trip has to be lossless
     * for that reason, and a test asserts that it is.
     *
     * @param  array<string, mixed>  $evidence
     */
    public static function fromEvidence(array $evidence): self
    {
        /** @var array<string, string> $extra */
        $extra = is_array($evidence['extra'] ?? null) ? $evidence['extra'] : [];

        return new self(
            line: is_numeric($evidence['line'] ?? null) ? (int) $evidence['line'] : 0,
            reference: is_string($evidence['reference'] ?? null) ? $evidence['reference'] : '',
            title: self::text($evidence, 'title'),
            artist: self::text($evidence, 'artist'),
            releaseTitle: self::text($evidence, 'release_title'),
            discNumber: self::number($evidence, 'disc_number'),
            trackNumber: self::number($evidence, 'track_number'),
            language: self::text($evidence, 'language'),
            isrc: self::text($evidence, 'isrc'),
            upc: self::text($evidence, 'upc'),
            legacyProvider: self::text($evidence, 'legacy_provider'),
            notes: self::text($evidence, 'notes'),
            extra: $extra,
        );
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private static function text(array $evidence, string $key): ?string
    {
        $value = $evidence[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private static function number(array $evidence, string $key): ?int
    {
        $value = $evidence[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
