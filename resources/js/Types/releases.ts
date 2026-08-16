/**
 * The shapes the release read models produce.
 *
 * Two things are worth reading twice.
 *
 * `validation.errors` and `readiness_problems` are **sentences from the
 * domain**, not codes. Every other refusal on this platform travels as a code
 * the interface translates; these do not, because they are produced by
 * `ValidateRelease` and `Release::readinessProblems()`, whose output is also
 * imploded into delivery-attempt records and matched on by `SubmitDelivery`.
 * Turning them into codes changes delivery semantics, so it is a backend
 * ticket of its own (REL-002) rather than something a UI ticket does quietly.
 * Until then these render in English while everything around them is
 * translated, and that is a recorded limitation, not an oversight.
 *
 * `actions` is presentation, never authorisation. The route middleware decides
 * who may act; `ReleaseMutationPolicy` decides what a status permits. These
 * flags exist so a field can be disabled with an explanation instead of
 * failing on save.
 */

export interface ReleaseRow {
    uuid: string;
    title: string;
    version_title: string | null;
    type: string;
    status: string;
    label_name: string | null;
    release_date: string | null;
    track_count: number;
    artist_count: number;
    has_cover: boolean;
    created_at: string | null;
}

export interface ReleasePage {
    rows: ReleaseRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface ReleaseFilters {
    status: string | null;
    type: string | null;
}

/** Described, never located: no path, no disk, no signed URL. */
export interface ReleaseCover {
    uuid: string;
    kind: string;
    status: string;
    width: number | null;
    height: number | null;
    byte_size: number | null;
    verified: boolean;
}

export interface ReleaseArtistCredit {
    uuid: string;
    name: string;
    role: string;
    position: number;
}

export interface ReleaseTrackRow {
    uuid: string;
    title: string;
    version_title: string | null;
    duration_ms: number | null;
    is_explicit: boolean;
    disc_number: number;
    track_number: number;
    is_focus_track: boolean;
    isrc: string | null;
}

/** Read from the catalogue. Never minted here. */
export interface ReleaseIdentifier {
    type: string;
    value: string;
    source: string;
}

export interface ReleaseValidation {
    valid: boolean;
    errors: string[];
    warnings: string[];
}

export interface ReleaseActions {
    may_edit: boolean;
    can_edit_details: boolean;
    can_edit_tracks: boolean;
    can_edit_artists: boolean;
    can_edit_cover: boolean;
    can_mark_ready: boolean;
    can_reopen: boolean;
}

export interface ReleaseDetail {
    uuid: string;
    title: string;
    version_title: string | null;
    type: string;
    status: string;
    label_name: string | null;
    catalogue_number: string | null;
    release_date: string | null;
    original_release_date: string | null;
    language_code: string;
    p_line: string | null;
    c_line: string | null;
    created_at: string | null;
    updated_at: string | null;
    cover: ReleaseCover | null;
    artists: ReleaseArtistCredit[];
    tracks: ReleaseTrackRow[];
    identifiers: ReleaseIdentifier[];
    validation: ReleaseValidation;
    readiness_problems: string[];
    actions: ReleaseActions;
}
