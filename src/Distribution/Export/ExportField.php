<?php

declare(strict_types=1);

namespace SaniTube\Distribution\Export;

/**
 * What a delivery spreadsheet may be asked to carry.
 *
 * **A closed vocabulary, because the columns are configuration.** Every
 * distributor's intake sheet is different — the column order, the headings and
 * which fields are mandatory all vary — so the mapping lives in
 * `config/distribution.php` and no distributor is named anywhere in this code.
 * What configuration may *not* do is invent a field: a heading pointing at
 * something SaniTube cannot answer would produce a column of blanks that reads,
 * to whoever uploads the sheet, as data the catalogue does not hold.
 *
 * **Nothing here is ever computed to fill a gap.** An unassigned ISRC is an
 * empty cell, never a generated one — SaniTube assigns identifiers deliberately
 * or not at all, and a plausible-looking code in a delivery sheet is how a
 * catalogue acquires an identifier nobody issued.
 */
enum ExportField: string
{
    // ---------------------------------------------------------- the release

    case ReleaseTitle = 'release_title';

    case ReleaseVersion = 'release_version';

    case ReleaseArtist = 'release_artist';

    case ReleaseType = 'release_type';

    /** Blank unless one has been assigned. Never invented. */
    case Upc = 'upc';

    case LabelName = 'label_name';

    case CatalogueNumber = 'catalogue_number';

    case ReleaseDate = 'release_date';

    case OriginalReleaseDate = 'original_release_date';

    case PLine = 'p_line';

    case CLine = 'c_line';

    // ------------------------------------------------------------ the track

    case DiscNumber = 'disc_number';

    case TrackNumber = 'track_number';

    case TrackTitle = 'track_title';

    case TrackVersion = 'track_version';

    case TrackArtist = 'track_artist';

    /**
     * Writers, producers, performers — name and role, as the credits state.
     *
     * One cell rather than a column per role, because which roles a form asks
     * for is the part that differs most between distributors. An operator whose
     * intake sheet wants a `Composer` column splits this one; an operator whose
     * sheet wants free text pastes it.
     */
    case TrackContributors = 'track_contributors';

    /** Blank unless one has been assigned. Never invented. */
    case Isrc = 'isrc';

    case LanguageCode = 'language_code';

    case IsFocusTrack = 'is_focus_track';

    /** Every store asks, and getting it wrong is what gets a release pulled. */
    case IsExplicit = 'is_explicit';

    case IsInstrumental = 'is_instrumental';

    case GenrePrimary = 'genre_primary';

    case GenreSecondary = 'genre_secondary';

    /** The recording's own ℗ line, which may differ from the release's. */
    case TrackPLine = 'track_p_line';

    // ------------------------------------------------------------- the file

    /**
     * What the master is called *in this package*.
     *
     * A rendering, never an identity. The manifest carries the track's uuid
     * beside it, and two tracks whose titles sanitise to the same string still
     * have two different uuids and two different checksums.
     */
    case FileName = 'file_name';

    /** What an operator compares against after uploading. */
    case FileChecksum = 'file_checksum';

    case FileBytes = 'file_bytes';

    case DurationSeconds = 'duration_seconds';

    /**
     * Whether an empty cell is a problem worth naming.
     *
     * Not a refusal: some distributors assign ISRCs on intake, and refusing to
     * export until every code exists would make the platform unusable for the
     * labels that rely on that. It is reported, once, in the package's own
     * warnings — where somebody deciding whether to upload can see it.
     */
    public function isWorthWarningAbout(): bool
    {
        return match ($this) {
            self::Isrc, self::Upc => true,
            default => false,
        };
    }
}
