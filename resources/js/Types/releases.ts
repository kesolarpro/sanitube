import type { CatalogIdentifier } from '@/Types/catalog';

/**
 * The shapes the release read models produce.
 *
 * `validation` and `readiness_problems` carry **issues, not sentences** — REL-002
 * closed that gap. Every refusal on this platform now travels as a code the
 * interface translates, including these, and `SubmitDelivery` decides whether a
 * distributor was unreachable from a code rather than by searching an English
 * message for a substring.
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

/**
 * Recorded from what somebody holds. Never minted here.
 *
 * `uuid` names one for revocation — CAT-005. `source` is what separates a
 * number a person typed from one a distributor sent back, and after the fact
 * nothing else does.
 */
export type ReleaseIdentifier = CatalogIdentifier;

/**
 * REL-002. One thing wrong with a release, as data rather than as a sentence.
 *
 * `code` is the contract and the thing the interface translates. `context`
 * carries the values a translation needs — a track number, a disc — so every
 * locale can put them in its own word order. `path` is what lets a message sit
 * beside the field it is about rather than in a pile at the bottom.
 */
export interface ValidationIssue {
    code: string;
    severity: 'ERROR' | 'WARNING';
    path: string;
    context: Record<string, string | number | boolean | null>;
}

export interface ReleaseValidation {
    valid: boolean;
    errors: ValidationIssue[];
    warnings: ValidationIssue[];
}

/**
 * ART-003. Whether this installation could generate a cover, and which numbers
 * disagree when it could not.
 *
 * `detail` carries the two settings that conflict — the requirement and what
 * the provider can actually make — because "generation unavailable" alone
 * leaves somebody re-reading provider documentation for an afternoon. Both are
 * settings, so there are two honest ways out and the operator picks one.
 */
export interface ArtworkGenerationRequest {
    uuid: string;
    status: string;
    /** Catalogue data the operator wrote — the only thing telling two attempts apart. */
    prompt: string;
    provider: string;
    model: string | null;
    /** A refusal code, never a sentence. */
    failure: string | null;
    /** The asset uuid. Never a storage key: where it lives is the storage service's answer. */
    asset: string | null;
    attempts: number;
    requested_at: string | null;
    completed_at: string | null;
}

export interface ArtworkGenerationReadiness {
    available: boolean;
    /** A refusal code, never a sentence. */
    refusal: string | null;
    detail: Record<string, string>;
    /**
     * ART-004. What is left in each rolling window. `null` means no ceiling is
     * configured for that window — reported as such rather than as a very large
     * number.
     */
    remaining: Record<string, number | null>;
    requests: ArtworkGenerationRequest[];
    can_request: boolean;
}

export interface ReleaseActions {
    may_edit: boolean;
    can_edit_details: boolean;
    can_edit_tracks: boolean;
    can_edit_artists: boolean;
    can_edit_cover: boolean;
    can_mark_ready: boolean;
    can_reopen: boolean;
    /**
     * Not gated on the release's status. A UPC is routinely issued after a
     * release is submitted, and locking the field then would leave the one
     * identifier a store keys on unenterable for exactly the releases that
     * need it.
     */
    can_assign_identifier: boolean;
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
    artwork_generation: ArtworkGenerationReadiness;
    artists: ReleaseArtistCredit[];
    tracks: ReleaseTrackRow[];
    identifiers: ReleaseIdentifier[];
    /** Which types a person may type in here. Decided by the server. */
    assignable_identifier_types: string[];
    validation: ReleaseValidation;
    readiness_problems: ValidationIssue[];
    actions: ReleaseActions;
}

/**
 * REL-004. What would cross to a distributor, and what still stops it.
 *
 * Three answers kept apart on purpose, because a release can be blocked by any
 * one of them while the other two are fine:
 *
 *   - `package` is the assembled content, or `null` with a `refusal` code.
 *   - `blocking_identifiers` are the UPC and per-track ISRC every delivery
 *     needs, whoever it goes to. They do **not** stop the package assembling.
 *   - `destinations_configured` is how many real distributors this
 *     installation has. Zero means a perfect package is still going nowhere.
 *
 * `prepared` means the package assembled. It does not mean the release may be
 * delivered, and the screen never says it does.
 */
export interface PackagedArtistCredit {
    name: string;
    role: string;
    position: number;
    isni: string | null;
}

export interface PackagedContributorCredit {
    name: string;
    role: string;
    ipi: string | null;
    isni: string | null;
}

export interface PackagedTrackRow {
    uuid: string;
    disc_number: number;
    track_number: number;
    title: string;
    version_title: string | null;
    isrc: string | null;
    language_code: string;
    is_instrumental: boolean;
    is_explicit: boolean;
    duration_ms: number | null;
    p_line: string | null;
    genre_primary: string | null;
    genre_secondary: string | null;
    master_asset_uuid: string;
    is_focus_track: boolean;
    artists: PackagedArtistCredit[];
    contributors: PackagedContributorCredit[];
}

export interface PackagedRelease {
    uuid: string;
    type: string;
    title: string;
    version_title: string | null;
    upc: string | null;
    catalogue_number: string | null;
    label_name: string | null;
    release_date: string | null;
    original_release_date: string | null;
    language_code: string;
    p_line: string | null;
    c_line: string | null;
    cover_asset_uuid: string;
    artists: PackagedArtistCredit[];
    tracks: PackagedTrackRow[];
}

export interface ReleasePackagePreparation {
    release: string;
    prepared: boolean;
    /** A refusal code, never a sentence from the server. */
    refusal: string | null;
    /** The codes or uuids the refusal carries, for the translated message. */
    refusal_context: string[];
    package: PackagedRelease | null;
    blocking_identifiers: ValidationIssue[];
    destinations_configured: number;
}
