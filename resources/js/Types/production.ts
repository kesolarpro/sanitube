/**
 * The shapes the production read models produce.
 *
 * Three fields the rows have are deliberately absent, and each is the kind that
 * gets added by accident later:
 *
 *   - the generation's `provider_job_id` — the handle a supplier's own API
 *     uses, and on a query-string-authenticated provider that string is a
 *     credential.
 *   - the generation's `prompt` and `lyrics` — the operator's own work, and an
 *     occasion list is not where it gets copied to.
 *
 * `attempted_providers` *is* here: which suppliers were asked is operational
 * fact rather than a secret, and the names are already in the operator's own
 * configuration. No endpoint and no key travel with them.
 */

/** Every outcome is present, including the zeros — never an absent key. */
export interface OccasionCounts {
    total: number;
    PENDING: number;
    CLAIMED: number;
    COMPLETED: number;
    CANCELLED: number;
    SKIPPED: number;
    FAILED: number;
}

export interface ProductionPlanRow {
    uuid: string;
    name: string;
    status: string;
    autonomy_mode: string;
    /**
     * Two different questions with two different answers: a plan can be granted
     * autonomy and be paused. An operator looking at a quiet month needs to
     * know which one it is.
     */
    may_generate_unattended: boolean;
    is_runnable: boolean;
    is_resumable: boolean;
    was_stopped_by_the_platform: boolean;
    target_track_count: number | null;
    cadence_days: number | null;
    halted_reason: string | null;
    halted_at: string | null;
    editorial_profile: string | null;
    occasions: OccasionCounts;
    created_at: string | null;
}

export interface ProductionPlanDetail extends Omit<ProductionPlanRow, 'occasions'> {
    slug: string;
    notes: string | null;
}

/** What a generation is, as far as this screen is concerned. */
export interface OccasionGeneration {
    uuid: string;
    status: string;
    provider: string;
}

export interface OccasionRow {
    uuid: string;
    scheduled_for: string;
    status: string;
    intent: string;
    /** A skip is information; a failure is something to look at. Kept apart. */
    was_skipped: boolean;
    was_set_by_a_person: boolean;
    outcome_reason: string | null;
    claimed_by: string | null;
    claimed_at: string | null;
    settled_at: string | null;
    attempted_providers: string[];
    generation: OccasionGeneration | null;
    is_cancellable: boolean;
}

export interface ProductionPlanPage {
    rows: ProductionPlanRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface ProductionOptions {
    status: string[];
    autonomy: string[];
    outcome: string[];
}
