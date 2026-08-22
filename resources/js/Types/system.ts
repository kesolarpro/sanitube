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

    /**
     * Whether the job itself declared that running it again converges.
     * Presentation only — the guard is `ResolveFailedJob`, which asks the
     * job's type and refuses regardless of what the screen offered.
     */
    retryable: boolean;
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

/**
 * Whether the installation is taking on background work at all.
 *
 * OPS-002. The global stop existed, worked, and was reported on no screen — an
 * installation somebody paused yesterday looked identical to a healthy one
 * while nothing was processed. `paused_by` is a name and never an address: an
 * operations screen is a screen people share.
 */
export interface BackgroundWorkView {
    paused: boolean;
    /** A machine code the interface renders, never a sentence from the server. */
    reason: string | null;
    paused_at: string | null;
    paused_by: string | null;
}

export interface Operations {
    health: OperationalHealthView;
    work: BackgroundWorkView;
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

/**
 * One line of the audit log.
 *
 * `actor.label` is a **snapshot** taken when the event was written, not a join
 * against the users table: a line about somebody who has since been renamed
 * should say who they were when they did it. There is deliberately no email
 * address — the log outlives the account and is copied into every backup.
 *
 * `context` has already been through the server's redaction. No URL, no token,
 * no credential and nothing long ever reaches this type, which is why it can
 * be rendered as-is.
 *
 * `outcome` of `'REFUSED'` is not an error state to hide. A refusal carries the
 * machine-readable `reason` the domain produced, and a run of them is the most
 * useful thing on this screen.
 */
export interface AuditActor {
    kind: 'user' | 'system' | 'guest';
    label: string | null;
    role: string | null;
}

export interface AuditRow {
    uuid: string;
    action: string;
    subject: string;
    subject_uuid: string | null;
    outcome: 'SUCCEEDED' | 'REFUSED';
    reason: string | null;
    significant: boolean;
    actor: AuditActor;
    ip_address: string | null;
    user_agent: string | null;
    context: Record<string, unknown> | null;
    occurred_at: string;
}

export interface AuditPage {
    rows: AuditRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface AuditFilters {
    action: string | null;
    subject: string | null;
    outcome: string | null;
    subject_uuid: string | null;
}

export interface AuditOptions {
    action: string[];
    subject: string[];
    outcome: string[];
}

/**
 * An account, as the users screen sees it.
 *
 * USR-001. This is the **one** payload in the platform that carries an email
 * address. Every other screen names a person and withholds it — an operations
 * banner has no business handing one out — and this screen is the exception
 * because the address *is* the account: it is what somebody signs in with.
 *
 * Never the password hash, never the remember token. `is_self` and
 * `is_last_owner` exist so the screen can disable a control and say why,
 * rather than offering one that refuses.
 */
export interface UserRow {
    uuid: string;
    name: string;
    email: string;
    role: 'OWNER' | 'ADMIN' | 'MEMBER';
    is_active: boolean;
    last_login_at: string | null;
    created_at: string | null;
    is_self: boolean;
    is_last_owner: boolean;
}

export interface UsersView {
    rows: UserRow[];
    roles: string[];
    /** Whether this reader may make or unmake an owner. */
    may_manage_owners: boolean;
    active_owners: number;
}

/**
 * STO-005. How many bytes this installation is holding, and where.
 *
 * **What the catalogue says it stored, never what a provider bills.** The two
 * disagree for real reasons — an abandoned multipart upload leaves parts the
 * platform never registered, a bucket may hold objects from before this
 * installation, versioning keeps what a delete removed — so a screen calling
 * this "your storage usage" would offer a number somebody reconciles against
 * an invoice and cannot.
 *
 * No capacity and no percentage: a percentage needs a denominator this
 * platform cannot honestly obtain. Inventing one from a free tier would be a
 * guess with a progress bar drawn around it.
 */
export interface StorageTotal {
    bytes: number | null;
    assets: number | null;
}

export interface StorageByKind {
    bytes: number;
    assets: number;
}

export interface StorageByDisk {
    disk: string;
    bytes: number;
    assets: number;
    /** Still costing, and waiting on a decision nobody has made. */
    trashed_bytes: number;
}

export interface StorageUsage {
    /**
     * False when the assets table could not be asked. Distinct from zero: an
     * installation storing nothing and one that could not be measured are
     * different answers, and a zero would report the second as the first.
     */
    measured: boolean;
    held: StorageTotal;
    trashed: StorageTotal;
    /** Pending and missing together: bytes the platform cannot vouch for. */
    unsure: StorageTotal;
    by_kind: Record<string, StorageByKind>;
    by_disk: StorageByDisk[];
}

/**
 * SYS-001. What this installation is, and whether it is well.
 *
 * **Nothing here is invented.** A version the platform made up would be worse
 * than none, because the question somebody asks this screen is "am I on the
 * release that fixed the thing", and a hardcoded string answers yes for ever.
 * Every field is null when it was not recorded, and the screen says which.
 */
export interface SystemInstallation {
    /** Null when the deployment recorded nothing. Honest, not broken. */
    version: string | null;
    /** Null when this is not a git checkout — a tarball, a cPanel upload. */
    commit: string | null;
    environment: string;
    debug: boolean;
    locale: string;
    /** Which frontend is actually being served, as the installer recorded it. */
    frontend: { sha: string | null; installed_at: string | null } | null;
}

export interface SystemRuntime {
    php: string;
    database_driver: string;
    /** The server version, never a connection string and never a credential. */
    database_version: string | null;
    config_cached: boolean;
}

/**
 * Whether the schema is the one this code expects.
 *
 * The count is not the answer; the difference is. Files updated without
 * migrations run means code against a schema missing columns, and it fails at
 * the first write rather than at boot.
 */
export interface SystemMigrations {
    measured: boolean;
    applied: number | null;
    /** Named rather than counted: the names say what changed. */
    pending: string[] | null;
    latest: string | null;
}

export interface SystemCheck {
    section: string;
    key: string;
    /** READY, WARNING, BLOCKER or UNKNOWN. UNKNOWN is never a pass. */
    verdict: string;
    summary: string;
    remediation: string | null;
}

export interface SystemDiagnosis {
    measured: boolean;
    counts: Record<string, number>;
    checks: SystemCheck[];
}

export interface SystemAbout {
    installation: SystemInstallation;
    runtime: SystemRuntime;
    migrations: SystemMigrations;
    diagnosis: SystemDiagnosis;
}
