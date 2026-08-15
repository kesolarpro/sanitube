/**
 * The shapes the catalogue read models produce.
 *
 * Every identifier here is a UUID. No internal numeric key, no disk, no path
 * and no bucket appears in any of these types — that is enforced in PHP by
 * `TrackDetailQuery` and asserted by `CatalogTracksTest`, and stating it in the
 * types as well means a future field cannot be added to a page without somebody
 * having to write the leak down first.
 */

export interface ArtistRef {
    uuid: string;
    name: string;
}

export interface TrackRow {
    uuid: string;
    title: string;
    version_title: string | null;
    artists: ArtistRef[];
    status: string;
    source: string;
    language_code: string;
    duration_ms: number | null;
    is_instrumental: boolean;
    is_explicit: boolean;
    isrc: string | null;
    updated_at: string | null;
}

export interface TrackPage {
    rows: TrackRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface TrackFilters {
    search: string | null;
    status: string | null;
    source: string | null;
    language: string | null;
    instrumental: string | null;
    explicit: string | null;
    artist: string | null;
}

export interface TrackAnalysis {
    succeeded: boolean;
    failed: boolean;
    analyzer: string | null;
    codec: string | null;
    container: string | null;
    sample_rate: number | null;
    bit_depth: number | null;
    channels: number | null;
    bitrate: number | null;
    loudness_lufs: number | null;
    peak_dbfs: number | null;
    analyzed_at: string | null;
}

export interface TrackMaster {
    uuid: string;
    kind: string;
    status: string;
    mime_type: string;
    byte_size: number;
    /** First twelve characters. The full digest belongs in an export. */
    checksum_short: string;
    duration_ms: number | null;
    sample_rate: number | null;
    bit_depth: number | null;
    channels: number | null;
    stored_at: string | null;
    verified_at: string | null;
    is_duplicate: boolean;
    analysis: TrackAnalysis | null;
}

export interface TrackDetail {
    uuid: string;
    title: string;
    version_title: string | null;
    status: string;
    source: string;
    language_code: string;
    is_instrumental: boolean;
    is_explicit: boolean;
    duration_ms: number | null;
    bpm: number | null;
    musical_key: string | null;
    genre_primary: string | null;
    genre_secondary: string | null;
    recording_year: number | null;
    p_line: string | null;
    created_at: string | null;
    updated_at: string | null;
    artists: (ArtistRef & { role: string })[];
    contributors: (ArtistRef & { role: string })[];
    composition: { uuid: string; title: string; status: string } | null;
    master: TrackMaster | null;
    identifiers: {
        uuid: string;
        type: string;
        namespace: string;
        value: string;
        is_authoritative: boolean;
        source: string;
        assigned_at: string | null;
    }[];
    releases: {
        uuid: string;
        title: string;
        status: string;
        disc_number: number | null;
        track_number: number | null;
        is_focus_track: boolean;
    }[];
}
