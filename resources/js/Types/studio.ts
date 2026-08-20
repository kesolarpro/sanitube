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

/**
 * What a generation capability is called on the wire.
 *
 * The strings are `GenerationCapability`'s own values, and the union is
 * written out rather than typed as `string` so that a capability renamed in
 * PHP fails the type check here instead of silently rendering nothing.
 */
export type GenerationCapability =
    | 'TEXT_TO_MUSIC'
    | 'LYRICS_TO_MUSIC'
    | 'INSTRUMENTAL'
    | 'REFERENCE_AUDIO'
    | 'AUDIO_TO_AUDIO'
    | 'EXTEND'
    | 'REMIX'
    | 'STEM_SEPARATION'
    | 'AUDIO_UNDERSTANDING'
    | 'LYRICS_TIMESTAMPS'
    | 'CANCEL'
    | 'WEBHOOK'
    | 'SELF_HOSTED';

export interface ProviderState {
    name: string | null;
    configured: boolean;
    available: boolean;
    /**
     * What this supplier can do — separate from whether it may be used now.
     * Empty when nothing is configured, and empty is not the same as unknown:
     * `configured` says which.
     */
    capabilities: GenerationCapability[];
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
    may_generate: boolean;
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

/**
 * Which actions the generation is in a state to receive.
 *
 * Presentation, never authorisation: the route middleware decides who may act
 * and the services decide what is permissible.
 */
export interface GenerationActions {
    may_act: boolean;
    can_cancel: boolean;
    can_select: boolean;
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
    actions: GenerationActions;
}
