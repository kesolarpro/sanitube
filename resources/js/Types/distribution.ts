import type { ValidationIssue } from '@/Types/releases';

/**
 * The shapes the distribution read models produce.
 *
 * Two absences are deliberate and load-bearing, and they are the same two the
 * API refuses to publish.
 *
 * There is **no `external_release_id`**. It identifies a record in somebody
 * else's account, and the question a label actually has is *"did this reach
 * them"* — which is what `has_external_reference` answers.
 *
 * There is **no `idempotency_key`**. It is the token that makes a repeat
 * submission recognisable, and a reader holding it could construct one.
 *
 * `failure_reason` and `summary` arrive truncated to one line. They are a
 * distributor's statement about its own service — the one class of provider
 * message this platform passes through — but the outage path stores a raw
 * exception message, and those quote URLs.
 */

export interface DeliveryRow {
    uuid: string;
    provider: string;
    status: string;
    release: { uuid: string; title: string } | null;
    attempt_count: number;
    has_external_reference: boolean;
    submitted_at: string | null;
    live_at: string | null;
    last_synced_at: string | null;
    created_at: string | null;
}

export interface DeliveryPage {
    rows: DeliveryRow[];
    next_cursor: string | null;
    previous_cursor: string | null;
    per_page: number;
}

export interface DeliveryFilters {
    status: string | null;
    provider: string | null;
}

/** Read from configuration. Never a probe — see DeliveryDetailQuery. */
export interface DistributorState {
    name: string;
    known: boolean;
    available: boolean;
    sandbox: boolean | null;
}

export interface DeliveryAttempt {
    uuid: string;
    action: string;
    outcome: string;
    summary: string | null;
    duration_ms: number | null;
    created_at: string | null;
}

export interface DeliveryActions {
    may_distribute: boolean;
    can_submit: boolean;
    can_sync: boolean;
    can_request_takedown: boolean;
    /** DIST-001-H1. Asks the distributor what it holds; hands nothing over. */
    can_reconcile: boolean;
    /** DIST-001-H1. Records what a person found when they looked. */
    can_resolve_manually: boolean;
}

export interface DeliveryDetail {
    uuid: string;
    provider: string;
    status: string;
    has_external_reference: boolean;

    /**
     * `PROVIDER_RESPONSE` · `PROVIDER_LOOKUP` · `MANUAL_OPERATOR`, or null
     * when nothing has been handed over. The last one means the platform never
     * received the value — a person reported it.
     */
    external_reference_source: string | null;
    failure_reason: string | null;
    submitted_at: string | null;
    delivered_at: string | null;
    live_at: string | null;
    /** Null means nobody has asked — not that nothing has changed. */
    last_synced_at: string | null;
    created_at: string | null;
    release: { uuid: string; title: string; status: string } | null;
    distributor: DistributorState;
    attempts: DeliveryAttempt[];
    actions: DeliveryActions;
}

export interface Destination {
    provider: string;
    known: boolean;
    available: boolean;
    sandbox: boolean | null;
    delivery: { uuid: string; status: string; submitted_at: string | null } | null;
    can_submit: boolean;
    can_validate: boolean;
}

export interface SendPayload {
    release: { uuid: string; title: string; status: string };
    release_is_ready: boolean;
    may_distribute: boolean;
    destinations: Destination[];
}

/** Flashed by the preflight action. Creates nothing; asks for an opinion. */
export interface Preflight {
    provider: string;
    valid: boolean;
    errors: ValidationIssue[];
    warnings: ValidationIssue[];
}

/**
 * A master as the delivery package names it.
 *
 * The uuid is the asset's public one, so the screen can ask the ordinary
 * minting endpoint for a link. There is deliberately no object key and no URL:
 * where a master lives is not a browser's business, and a page that arrived
 * carrying twelve signed links would have created twelve credentials for
 * somebody who opened it to read a filename.
 */
export interface PackagedMaster {
    disc_number: number;
    track_number: number;
    file_name: string;
    sha256: string;
    bytes: number | null;
    asset_uuid: string;
}

export interface BuiltPackage {
    built_at: string;
    track_count: number;
    warnings: string[];
    tracks: PackagedMaster[];
}

export interface PackagePayload {
    release: { uuid: string; title: string };
    release_is_ready: boolean;
    /** Null until somebody builds one. Read from storage, not from a row. */
    package: BuiltPackage | null;
}
