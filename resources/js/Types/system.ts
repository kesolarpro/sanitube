/**
 * The shapes the operations read models produce.
 *
 * Two absences carry this module.
 *
 * A job row has **no `payload`**. A queued job's payload is the serialised job
 * object, and "we believe nothing sensitive is in there" is not a boundary —
 * what a person watching a queue needs is the kind of work and the timestamps.
 *
 * A failed job has **no stack trace**, only `error`: the exception's first
 * line, truncated. The trace names absolute paths and the application's
 * directory layout; the first line is what says whether this is an outage or a
 * bug.
 *
 * Every count is `number | null`, and null means **unknown**: an installation
 * whose queue runs on Redis has no database tables to count, and rendering 0
 * there would say "nothing is waiting".
 */

export interface QueueSummary {
    driver: string;
    readable: boolean;
    pending: number | null;
    reserved: number | null;
    failed: number | null;
    oldest_pending_at: string | null;
}

export interface PendingJobRow {
    id: number;
    queue: string;
    name: string | null;
    attempts: number;
    reserved: boolean;
    available_at: string;
    created_at: string;
}

export interface FailedJobRow {
    uuid: string;
    queue: string;
    connection: string;
    name: string | null;
    error: string | null;
    failed_at: string | null;
}

export interface JobList<T> {
    readable: boolean;
    rows: T[];
}

export interface HealthCheck {
    [key: string]: unknown;
}

export interface OperationalHealthView {
    state: 'FRESH' | 'STALE' | 'UNKNOWN';
    checked_at: string | null;
    stale_after_seconds: number;
    storage: { provider: string | null; healthy: boolean | null; temporary_urls: boolean | null; [key: string]: unknown };
    ai: { name: string | null; available: boolean | null };
    generation: { name: string | null; available: boolean | null };
    distributors: { name?: string | null; available: boolean | null }[];
    capabilities: { healthy: boolean | null; items?: unknown[] };
}

/**
 * `taken_at` of null means **no backup has ever been taken** — not "unknown".
 * Never backed up and backed up a long time ago are different problems, and a
 * dash for both is how the first one goes unnoticed for months.
 */
export interface BackupSummary {
    readable: boolean;
    count: number | null;
    taken_at: string | null;
    destination_configured: boolean;
}

export interface Operations {
    health: OperationalHealthView;
    scheduler: { last_run_at: string | null; seconds_since: number | null };
    queue: QueueSummary;
    backups: BackupSummary;
    runtime: {
        php_version: string;
        environment: string;
        debug: boolean;
        queue_driver: string;
        cache_driver: string;
        session_driver: string;
        database_driver: string;
    };
}
