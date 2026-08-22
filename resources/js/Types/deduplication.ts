/**
 * The shapes the duplicate queue produces.
 *
 * Two things here are worth stating in the types rather than leaving to the
 * markup.
 *
 * `similarity` is **nullable**, and null is not zero. A checksum finding was
 * never measured acoustically, and rendering 100% for one would put a figure on
 * the screen that no comparison produced.
 *
 * `is_measured` is sent by the server rather than derived from `level` in the
 * bundle. ADR-0016's distinction between what the platform observed and what it
 * inferred is the one thing this screen must not get wrong, and a component
 * that re-derives it is a component that can drift from the domain.
 *
 * There is **no path, no disk, no signed URL and no original filename** on
 * either side of a finding, for the same reason the asset list omits them — and
 * because a reviewer who picks the copy to keep by its name is deciding on the
 * least reliable thing about it.
 */

export interface DuplicateSide {
    uuid: string;
    byte_size: number | null;
    duration_ms: number | null;
    mime_type: string | null;
    checksum_short: string;
    status: string;
    is_trashed: boolean;
    created_at: string | null;
}

export interface DuplicateRow {
    uuid: string;
    level: string;
    basis: string;
    decision: string;
    is_measured: boolean;
    similarity: number | null;
    overlap_frames: number | null;
    algorithm: string | null;
    detected_at: string;
    decided_at: string | null;
    asset: DuplicateSide | null;
    related_asset: DuplicateSide | null;
}

export interface DuplicatePage {
    rows: DuplicateRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
    /**
     * Findings nobody has answered yet, whatever this page is filtered to.
     *
     * DUP-001. A cursor-paginated list has no total by design — that is what
     * makes it cheap over a large table — so a reviewer had no way to tell
     * whether the backlog was shrinking. This is deliberately not the count of
     * the filtered page: what a person wants to know between decisions is how
     * many are still theirs to make.
     */
    open: number;
}

export interface DuplicateFilters {
    decision: string | null;
    level: string | null;
}
