<?php

declare(strict_types=1);

namespace SaniTube\Media;

/**
 * What the file says about itself.
 *
 * TAG-001. `ffprobe -show_format` has always returned `format.tags` — ID3 on an
 * MP3, Vorbis comments on a FLAC, `©nam`-style atoms on an M4A, all normalised
 * by ffprobe into one flat map — and SaniTube has always stored the whole probe
 * payload in `audio_analyses.raw` and then read six numbers out of it. **The
 * tags were already in the database, already paid for, and never looked at.**
 *
 * For a catalogue of nine hundred files that is the difference between typing
 * nine hundred titles and correcting the handful that are wrong.
 *
 * **A tag is evidence, not truth.** Files acquire tags from whatever last
 * touched them: a ripper, a DAW export template, a download. `TALB` says
 * "Unknown Album" on a great many otherwise perfectly tagged files. So this
 * class reads and normalises; it decides nothing about what the catalogue says.
 *
 * **Only the fields worth trusting.** Comment, encoder, cover art, replaygain
 * and the long tail of vendor-specific frames are ignored — not because they
 * are harmful but because every field carried here is a field somebody has to
 * review, and a review screen full of `Lavf58.76.100` teaches people to skim.
 */
final readonly class EmbeddedTags
{
    /** Long enough for any real title; short enough that a corrupt frame cannot flood a row. */
    private const MAX_LENGTH = 255;

    private function __construct(
        public ?string $title = null,
        public ?string $artist = null,
        public ?string $album = null,
        public ?string $albumArtist = null,
        public ?string $genre = null,
        public ?int $trackNumber = null,
        public ?int $year = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * Read from a stored ffprobe payload.
     *
     * Tolerant of everything, because this runs over files nobody curated.
     * Anything missing, misspelt, empty or of the wrong type is simply absent
     * from the result — a tag that could not be read is indistinguishable from
     * a tag that was never there, and both mean "ask a person".
     *
     * @param  array<string, mixed>  $probe
     */
    public static function fromProbe(array $probe): self
    {
        $format = $probe['format'] ?? null;
        $tags = is_array($format) ? ($format['tags'] ?? null) : null;

        if (! is_array($tags)) {
            return self::none();
        }

        // Case-insensitively, because the same tag arrives as `TITLE` from a
        // FLAC and `title` from an MP3 and `Title` from something that got
        // there via iTunes.
        $lower = [];

        foreach ($tags as $key => $value) {
            if (is_string($key)) {
                $lower[strtolower($key)] = $value;
            }
        }

        return new self(
            title: self::text($lower, ['title']),
            artist: self::text($lower, ['artist']),
            album: self::text($lower, ['album']),
            // `album_artist` is the ffprobe spelling; `albumartist` is what a
            // Vorbis comment carries.
            albumArtist: self::text($lower, ['album_artist', 'albumartist']),
            genre: self::text($lower, ['genre']),
            trackNumber: self::trackNumber($lower),
            year: self::year($lower),
        );
    }

    public function isEmpty(): bool
    {
        return $this->title === null
            && $this->artist === null
            && $this->album === null
            && $this->albumArtist === null
            && $this->genre === null
            && $this->trackNumber === null
            && $this->year === null;
    }

    /**
     * As data, for the review screen — only the fields that are actually there.
     *
     * Absent rather than null, so a reviewer's list of "what the file says"
     * contains only claims, and a file with two tags does not present five
     * empty rows as though something had been looked for and not found.
     *
     * @return array<string, string|int>
     */
    public function toArray(): array
    {
        $claims = [
            'title' => $this->title,
            'artist' => $this->artist,
            'album' => $this->album,
            'album_artist' => $this->albumArtist,
            'genre' => $this->genre,
            'track_number' => $this->trackNumber,
            'year' => $this->year,
        ];

        return array_filter($claims, static fn (string|int|null $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $tags
     * @param  list<string>  $names
     */
    private static function text(array $tags, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $tags[$name] ?? null;

            if (! is_string($value) && ! is_int($value)) {
                continue;
            }

            // Control characters stripped: a tag ends up in a page, in a log
            // and eventually in a delivery, and a newline in any of those is
            // somebody else's parsing problem.
            $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value));

            if ($clean !== '') {
                return mb_substr($clean, 0, self::MAX_LENGTH);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private static function trackNumber(array $tags): ?int
    {
        // Routinely written as "3/12". The total is not a track number and is
        // discarded rather than parsed into one.
        $raw = self::text($tags, ['track']);

        if ($raw === null || preg_match('/^\d{1,4}/', $raw, $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[0];

        return $number > 0 ? $number : null;
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    private static function year(array $tags): ?int
    {
        // `date` is frequently a full ISO date and sometimes just a year.
        $raw = self::text($tags, ['date', 'year', 'originalyear']);

        if ($raw === null || preg_match('/(\d{4})/', $raw, $matches) !== 1) {
            return null;
        }

        $year = (int) $matches[1];

        // A bound rather than a validation: 1000 and 9999 are what a corrupt
        // frame produces, and neither is a recording date.
        return $year >= 1860 && $year <= 2200 ? $year : null;
    }
}
