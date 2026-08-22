import type { StorageUsage } from '@/Types/system';

/**
 * The shape `DashboardQuery` produces.
 *
 * Every count is `number | null`, and the null is load-bearing rather than
 * defensive typing: it is what the interface renders as an em dash instead of
 * a zero when a figure could not be taken. Widening these to `number` would
 * quietly turn "unknown" into "none" at the type level.
 */

export type StatusCounts = Record<string, number>;

export interface ProviderState {
    name: string | null;
    /** null when the provider threw while being asked — not the same as a refusal. */
    available: boolean | null;
}

export interface CapabilityItem {
    key: string;
    label: string;
    status: 'available' | 'unavailable' | 'degraded' | 'optional';
    detail: string | null;
    remediation: string | null;
    required: boolean;
}

export type OperationalStateName = 'FRESH' | 'STALE' | 'UNKNOWN';

export interface OperationalState {
    /**
     * STALE blanks every verdict back to null: a snapshot from three hours ago
     * is not evidence about now, and a stale `healthy` is how a dashboard
     * reports green through an outage.
     */
    state: OperationalStateName;
    checked_at: string | null;
    stale_after_seconds: number;
    storage: {
        provider: string | null;
        healthy: boolean | null;
        checks: Record<string, boolean>;
        temporary_urls: boolean | null;
        detail: string | null;
    };
    ai: ProviderState;
    generation_provider: ProviderState;
    distributors: ProviderState[];
    capabilities: {
        healthy: boolean | null;
        items: CapabilityItem[];
    };
}

export interface DashboardSnapshot {
    catalogue: {
        tracks: number | null;
        releases: number | null;
        artists: number | null;
        compositions: number | null;
        contributors: number | null;
        assets: number | null;
    };
    tracks_by_status: StatusCounts | null;
    releases_by_status: StatusCounts | null;
    ingestion: {
        batches_by_status: StatusCounts | null;
        candidates_by_status: StatusCounts | null;
    };
    media: {
        analyses: number | null;
        succeeded: number | null;
        failed: number | null;
    };
    generation: {
        by_status: StatusCounts | null;
    };
    distribution: {
        deliveries_by_status: StatusCounts | null;
    };
    /**
     * STO-005. How many bytes this installation is holding.
     *
     * Beside the asset count rather than instead of it, because they answer
     * different questions: a thousand previews and a thousand masters are the
     * same count and three orders of magnitude apart.
     */
    storage_usage: StorageUsage;
    jobs: {
        pending: number | null;
        failed: number | null;
    };
    /**
     * External state, read from the last scheduled probe. Never probed during
     * the request — see DashboardQuery::operational().
     */
    operational: OperationalState;
}
