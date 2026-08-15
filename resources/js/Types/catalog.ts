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

export interface ArtistRow {
    uuid: string;
    name: string;
    sort_name: string | null;
    type: string;
    status: string;
    country: string | null;
    is_owner_controlled: boolean;
    track_count: number;
    release_count: number;
    updated_at: string | null;
}

export interface ArtistPage {
    rows: ArtistRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface ArtistFilters {
    search: string | null;
    status: string | null;
    type: string | null;
    country: string | null;
    owner_controlled: string | null;
}

export interface ArtistDetail {
    uuid: string;
    name: string;
    sort_name: string | null;
    slug: string;
    type: string;
    status: string;
    country: string | null;
    primary_locale: string;
    biography: string | null;
    is_owner_controlled: boolean;
    track_count: number;
    release_count: number;
    created_at: string | null;
    updated_at: string | null;
    identifiers: {
        uuid: string;
        type: string;
        namespace: string;
        value: string;
        is_authoritative: boolean;
        source: string;
        assigned_at: string | null;
    }[];
    tracks: { uuid: string; title: string; status: string; role: string }[];
    /** True when the server capped the list; stated so a short list is not read as a complete one. */
    tracks_truncated: boolean;
    tracks_shown: number;
}

export interface ContributorRow {
    uuid: string;
    name: string;
    has_distinct_legal_name: boolean;
    type: string;
    country: string | null;
    composition_count: number;
    track_count: number;
    updated_at: string | null;
}

export interface ContributorPage {
    rows: ContributorRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface ContributorFilters {
    search: string | null;
    type: string | null;
    country: string | null;
}

interface IdentifierRef {
    uuid: string;
    type: string;
    namespace: string;
    value: string;
    is_authoritative: boolean;
    source: string;
    assigned_at: string | null;
}

export interface ContributorDetail {
    uuid: string;
    name: string;
    /** Kept distinct from `display_name`: they are different facts about a person. */
    legal_name: string;
    display_name: string | null;
    type: string;
    country: string | null;
    notes: string | null;
    composition_count: number;
    track_count: number;
    created_at: string | null;
    updated_at: string | null;
    identifiers: IdentifierRef[];
    tracks: { uuid: string; title: string; status: string; role: string }[];
    compositions: {
        uuid: string;
        title: string;
        status: string;
        role: string;
        /** A catalogue credit, NOT ownership and NOT a royalty entitlement. */
        credited_share: number | null;
    }[];
    tracks_shown: number;
    tracks_truncated: boolean;
    compositions_shown: number;
    compositions_truncated: boolean;
}

export interface CompositionRow {
    uuid: string;
    title: string;
    alternative_title: string | null;
    language_code: string;
    created_year: number | null;
    is_public_domain: boolean;
    status: string;
    iswc: string | null;
    track_count: number;
    contributor_count: number;
    updated_at: string | null;
}

export interface CompositionPage {
    rows: CompositionRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface CompositionFilters {
    search: string | null;
    status: string | null;
    language: string | null;
    public_domain: string | null;
}

export interface CompositionDetail {
    uuid: string;
    title: string;
    alternative_title: string | null;
    language_code: string;
    created_year: number | null;
    is_public_domain: boolean;
    status: string;
    track_count: number;
    contributor_count: number;
    created_at: string | null;
    updated_at: string | null;
    identifiers: IdentifierRef[];
    contributors: {
        uuid: string;
        name: string;
        role: string;
        /** A catalogue credit, NOT ownership. */
        credited_share: number | null;
    }[];
    recordings: { uuid: string; title: string; version_title: string | null; status: string }[];
    contributors_shown: number;
    contributors_truncated: boolean;
    recordings_shown: number;
    recordings_truncated: boolean;
}
