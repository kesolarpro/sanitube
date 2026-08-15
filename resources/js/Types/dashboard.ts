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
    name: string;
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
        provider: ProviderState;
    };
    distribution: {
        deliveries_by_status: StatusCounts | null;
        distributors: ProviderState[];
    };
    jobs: {
        pending: number | null;
        failed: number | null;
    };
    storage: {
        provider: string;
        /** null when the provider could not be probed at all. */
        healthy: boolean | null;
        checks: Record<string, boolean>;
        temporary_urls: boolean | null;
        detail: string | null;
    };
    capabilities: {
        healthy: boolean;
        items: CapabilityItem[];
        ai: ProviderState;
    };
}
