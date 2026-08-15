/**
 * The shapes the studio read models produce.
 *
 * Three fields the database has are deliberately absent, and each is the kind
 * that gets added by accident later:
 *
 *   - `provider_job_id` — the handle the provider's own API uses for the job.
 *   - `source_reference` on a result — where the provider serves the audio
 *     from, routinely a time-limited signed URL, so a bearer credential.
 *   - `raw` — the provider's whole response, which contains both of the above.
 *
 * `has_audio` is what replaces the reference: whether there is anything to
 * import, without saying where from.
 */

export interface ProviderState {
    name: string | null;
    configured: boolean;
    available: boolean;
}

/** Every state is present, including the ones at zero — never an absent key. */
export interface GenerationCounts {
    total: number;
    DRAFT: number;
    QUEUED: number;
    PROCESSING: number;
    COMPLETED: number;
    FAILED: number;
    CANCELLED: number;
}

/** UNKNOWN is the default and stays the default; it is never inferred. */
export interface RightsCounts {
    UNKNOWN: number;
    ALLOWED: number;
    RESTRICTED: number;
    PROHIBITED: number;
    REVIEW_REQUIRED: number;
}

export interface StudioOverview {
    provider: ProviderState;
    generations: GenerationCounts;
    rights: RightsCounts;
    projects: number;
}

export interface ProjectRow {
    uuid: string;
    name: string;
    status: string;
    target_track_count: number | null;
    generation_count: number;
    default_language: string | null;
    default_genre: string | null;
    accepts_generations: boolean;
    created_at: string | null;
}

export interface ProjectPage {
    rows: ProjectRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface ProjectDetail {
    uuid: string;
    name: string;
    status: string;
    target_track_count: number | null;
    default_language: string | null;
    default_genre: string | null;
    default_style_prompt: string | null;
    accepts_generations: boolean;
    created_at: string | null;
}

export interface GenerationRow {
    uuid: string;
    status: string;
    provider: string;
    prompt: string;
    instrumental: boolean;
    language_code: string;
    commercial_rights_status: string;
    result_count: number;
    created_at: string | null;
    completed_at: string | null;
}

export interface GenerationPage {
    rows: GenerationRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface GenerationFilters {
    status: string | null;
    commercial_rights_status: string | null;
}

export interface GenerationResultRow {
    uuid: string;
    title: string | null;
    duration_ms: number | null;
    selected: boolean;
    has_audio: boolean;
    asset_uuid: string | null;
    candidate_uuid: string | null;
}

export interface GenerationDetail {
    uuid: string;
    status: string;
    provider: string;
    model: string | null;
    prompt: string;
    style_prompt: string | null;
    lyrics: string | null;
    instrumental: boolean;
    language_code: string;
    commercial_rights_status: string;
    project: { uuid: string; name: string } | null;
    failure_reason: string | null;
    poll_count: number;
    submitted_at: string | null;
    completed_at: string | null;
    last_polled_at: string | null;
    created_at: string | null;
    results: GenerationResultRow[];
}
