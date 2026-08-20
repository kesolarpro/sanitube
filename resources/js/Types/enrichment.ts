/**
 * The shapes the enrichment review queue produces.
 *
 * **The four levels are four separate objects, and that is the point.**
 * ADR-0016 draws three lines and the catalogue draws the fourth, and a
 * reviewer who cannot see which is which will accept a model's guess believing
 * it was measured. Flattening these into one row object in the bundle would
 * make that mistake possible in a single careless template edit.
 *
 * `canonical` is **nullable, and null is not empty**. Null means this asset
 * masters no track, so the catalogue says nothing about this recording yet —
 * which is a different statement from "the catalogue says nothing in these
 * fields". Neither is ever filled in from `suggested`.
 *
 * `confidence` is the model's opinion of its own answer, shown and never acted
 * on. It is more output, not evidence about the output.
 *
 * There is **no path, no disk, no signed URL and no original filename**
 * anywhere here, for the reason the asset list and the duplicate queue omit
 * them: a storage layout published in page source is one that can no longer be
 * changed.
 */

/** What the catalogue currently says. A person put it there. */
export interface CanonicalMetadata {
    track_uuid: string;
    title: string;
    language_code: string;
    genre_primary: string | null;
    genre_secondary: string | null;
    status: string;
}

/** What the platform observed from the file itself. Facts. */
export interface MeasuredMetadata {
    duration_ms: number | null;
    codec: string | null;
    channels: number | null;
    sample_rate: number | null;
}

/** What a specialised model reported. Narrow, purpose-built, still a guess. */
export interface ProposedMetadata {
    language: string | null;
    language_hint: string | null;
    contradicts_the_hint: boolean;
    provider: string;
    covered_seconds: number | null;
}

/** What a language model interpreted. The weakest level. */
export interface SuggestedMetadata {
    title: string | null;
    language: string | null;
    genres: string[];
    moods: string[];
    themes: string[];
    description: string | null;
    rationale: string | null;
}

export interface SuggestionRow {
    uuid: string;
    asset_uuid: string | null;
    task: string;
    status: string;
    provider: string;
    model: string | null;
    prompt_version: string;
    confidence: number | null;
    evidence_is_current: boolean;
    suggested_at: string;
    decided_at: string | null;
    canonical: CanonicalMetadata | null;
    measured: MeasuredMetadata | null;
    proposed: ProposedMetadata | null;
    suggested: SuggestedMetadata;
    actions: {
        decidable: boolean;
        has_track: boolean;
    };
}

export interface SuggestionPage {
    rows: SuggestionRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface SuggestionFilters {
    status: string | null;
}

/** The reviewer's version of a suggestion, sent when accepting. */
export interface SuggestionEdit {
    title: string;
    language_code: string;
    genres: string;
}
