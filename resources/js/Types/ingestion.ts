/**
 * The shapes the ingestion read models produce.
 *
 * Two absences are deliberate and load-bearing.
 *
 * There is **no `source_reference`** anywhere here. An item's source reference
 * is an object key in somebody's storage, and a screen is not the place to
 * publish the shape of it. `original_filename` — the basename, the thing a
 * person recognises — is what a failure report needs.
 *
 * There is **no `failure_message`**, only `failure_code`. A message quotes
 * whatever threw, and those quotes routinely contain a path or a bucket name.
 * The code is what somebody acts on, and it is translated rather than echoed.
 */

export interface ItemCounts {
    total: number;
    PENDING: number;
    PROCESSING: number;
    IMPORTED: number;
    DUPLICATE: number;
    NEEDS_REVIEW: number;
    FAILED: number;
    SKIPPED: number;
}

export interface BatchRow {
    uuid: string;
    source: string;
    status: string;
    items: ItemCounts;
    started_at: string | null;
    completed_at: string | null;
    created_at: string | null;
}

export interface BatchPage {
    rows: BatchRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface BatchFilters {
    status: string | null;
    source: string | null;
}

export interface BatchProgress {
    uuid: string;
    status: string;
    source: string;
    started_at: string | null;
    completed_at: string | null;
    items: ItemCounts;
}

export interface ItemRow {
    uuid: string;
    original_filename: string;
    status: string;
    attempt_count: number;
    failure_code: string | null;
    candidate_uuid: string | null;
    has_manifest_row: boolean;
    created_at: string | null;
    updated_at: string | null;
}

export interface ItemPage {
    rows: ItemRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface CandidateRow {
    uuid: string;
    status: string;
    source: string;
    original_filename: string;
    suggested_title: string | null;
    is_duplicate: boolean;
    has_conflicts: boolean;
    reviewed_at: string | null;
    created_at: string | null;
}

export interface CandidatePage {
    rows: CandidateRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface CandidateFilters {
    status: string | null;
    source: string | null;
    awaiting_review: string | null;
}

export interface CandidateAnalysis {
    succeeded: boolean;
    analyzer: string;
    duration_ms: number | null;
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

/** Everything under `claimed` is asserted by a person, not established here. */
export interface ManifestClaim {
    title: string | null;
    artist: string | null;
    release_title: string | null;
    disc_number: number | null;
    track_number: number | null;
    language: string | null;
    isrc: string | null;
    upc: string | null;
    legacy_provider: string | null;
    notes: string | null;
}

export interface ManifestFinding {
    code: string;
    isrc?: string;
    upc?: string;
    held_by_track?: string;
    release?: string;
    message?: string;
}

export interface CandidateManifest {
    claimed: ManifestClaim;
    line: number | null;
    extra: Record<string, string>;
    conflicts: ManifestFinding[];
    observations: ManifestFinding[];
}

export interface CandidateAsset {
    uuid: string;
    kind: string;
    status: string;
    mime_type: string | null;
    byte_size: number | null;
    checksum_short: string;
    duration_ms: number | null;
    preview: { available: boolean; reason: string | null };
}

/**
 * Which decisions the candidate is in a state to receive.
 *
 * Presentation, never authorisation: the route middleware decides who may act
 * and the domain services decide what is permissible. These exist so a button
 * can be disabled with an explanation instead of failing when pressed.
 */
export interface CandidateActions {
    may_review: boolean;
    can_promote: boolean;
    can_promote_with_override: boolean;
    override_requires_note: boolean;
    can_reject: boolean;
    can_revise: boolean;
}

export interface CandidateDetail {
    uuid: string;
    status: string;
    source: string;
    original_filename: string;
    suggested_title: string | null;
    suggested_title_source: string | null;
    failure_code: string | null;
    asset: CandidateAsset | null;
    analysis: CandidateAnalysis | null;
    duplicate: {
        asset_uuid: string;
        track: { uuid: string; title: string } | null;
    } | null;
    manifest: CandidateManifest | null;
    actions: CandidateActions;
    review: {
        reviewed_at: string | null;
        note: string | null;
        promoted_track: { uuid: string; title: string } | null;
    };
    created_at: string | null;
    updated_at: string | null;
}

/**
 * BULK-001. What the catalogue import screen works from.
 *
 * `waiting` is read from storage on every render, which is what makes the
 * import survive a refresh: the objects in the inbox *are* the durable state,
 * and nothing needed inventing to make that true. What cannot survive is a
 * file that was picked and never uploaded — that exists only in the browser,
 * and the screen says so rather than implying a resume it cannot perform.
 */
export interface InboxFile {
    /** The storage key. What `POST /ingestion/batches` refers to. */
    reference: string;
    name: string;
    /** Null when the object lists but will not measure. */
    byte_size: number | null;
}

export interface ImportCapability {
    /** True when the configured store can take a write that never enters PHP. */
    direct: boolean;
    /** Empty means any type. */
    accepted_types: string[];
    maximum_bytes: number;
    /** How many files one deposit request may declare. */
    per_request: number;
    /** How many files the browser uploads at once. */
    concurrency: number;
    /** How many files one batch may carry. */
    max_batch: number;
    waiting: {
        /** False means the inbox could not be read — not that it is empty. */
        available: boolean;
        files: InboxFile[];
    };
}

/**
 * A file's journey on this screen, before the batch takes over.
 *
 * These are the *deposit* states only. Once a batch starts, what happens to a
 * file is an `IngestionItem` and is reported by the batch screens, which
 * already know how to say IMPORTED, DUPLICATE, NEEDS_REVIEW and FAILED.
 */
export type DepositState = 'WAITING' | 'UPLOADING' | 'UPLOADED' | 'FAILED' | 'CANCELLED';

export interface DepositItem {
    /** Stable across re-renders; a filename is not unique. */
    id: number;
    name: string;
    size: number;
    /** What the browser thinks it is. Never what decides. */
    type: string;
    state: DepositState;
    /** 0–100. */
    progress: number;
    /** A refusal code, never a sentence from the server. */
    code: string | null;
    /** The inbox key, once the bytes are there. */
    reference: string | null;
}
