<script setup lang="ts">
import { computed } from 'vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import MetricCard from '@/Components/Ui/MetricCard.vue';
import StorageUsageCard from '@/Components/Storage/StorageUsageCard.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { trans } from '@/Support/i18n';
import type { DashboardSnapshot, ProviderState, StatusCounts } from '@/Types/dashboard';
import { dateTime } from '@/Support/format';
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/Types/inertia';

/**
 * The operational overview.
 *
 * Nothing here is computed from anything but `snapshot`, and the snapshot is
 * counted server-side in one pass. The rule the whole screen turns on:
 * **`null` is not `0`.** A figure that could not be taken renders as an em dash,
 * never as a zero, because "no failed jobs" and "we could not look" send an
 * operator in opposite directions.
 *
 * Buckets with no rows are hidden rather than listed at zero. A release
 * pipeline has twelve delivery states and an install will typically be using
 * three; printing nine zeroes buries the three that matter.
 */
const props = defineProps<{ snapshot: DashboardSnapshot }>();

const CATALOGUE_ORDER = ['tracks', 'releases', 'artists', 'compositions', 'contributors', 'assets'] as const;

function occupied(counts: StatusCounts | null): [string, number][] {
    return counts === null ? [] : Object.entries(counts).filter(([, total]) => total > 0);
}

const page = usePage<SharedProps>();
const operational = computed(() => props.snapshot.operational);
const capabilities = computed(() => operational.value.capabilities.items);

/*
 * Only capabilities that are both required and unavailable. A missing optional
 * capability is a fact for the operations screen, not an alarm on the
 * dashboard — and an alarm nobody can act on is one people learn to scroll past.
 */
const blocking = computed(() =>
    capabilities.value.filter((capability) => capability.status === 'unavailable' && capability.required),
);

/** Every provider, whatever its subsystem, as one list. */
const providers = computed<(ProviderState & { subsystem: string })[]>(() => [
    { subsystem: trans('ui.dashboard.subsystem.ai'), ...operational.value.ai },
    { subsystem: trans('ui.dashboard.subsystem.generation'), ...operational.value.generation_provider },
    ...operational.value.distributors.map((distributor) => ({
        subsystem: trans('ui.dashboard.subsystem.distribution'),
        ...distributor,
    })),
]);

/** When the external state was last actually measured, in the reader's locale. */
const checkedAt = computed(() => dateTime(operational.value.checked_at, page.props.app.locale));

/**
 * Availability as a status the badge already knows how to colour.
 *
 * `null` becomes UNKNOWN rather than UNAVAILABLE: a provider that threw while
 * being asked has not refused, and reporting a network blip as a deliberate
 * "no" sends somebody to the wrong log.
 */
function availability(available: boolean | null): string {
    if (available === null) {
        return 'UNKNOWN';
    }

    return available ? 'AVAILABLE' : 'UNAVAILABLE';
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.dashboard.title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.dashboard.subtitle') }}</p>
        </div>

        <!--
            The provenance of every external figure below. Without it a reader
            cannot tell a live green from one measured before lunch, and that
            distinction is the entire point of not probing during the request.
        -->
        <AppAlert v-if="operational.state !== 'FRESH'" :tone="operational.state === 'STALE' ? 'warning' : 'info'">
            <p class="font-medium">
                {{ operational.state === 'STALE' ? trans('ui.dashboard.health_stale') : trans('ui.dashboard.health_unknown') }}
            </p>
            <p class="mt-0.5">
                <template v-if="operational.checked_at">{{ trans('ui.dashboard.last_checked') }} {{ checkedAt }}</template>
                <template v-else>{{ trans('ui.dashboard.never_checked') }}</template>
            </p>
        </AppAlert>

        <!-- First, because it explains the missing figures further down. -->
        <AppAlert v-if="blocking.length > 0" tone="danger">
            <p class="font-medium">{{ trans('ui.dashboard.blocking_capabilities') }}</p>
            <ul class="mt-1 list-disc space-y-0.5 pl-4">
                <li v-for="capability in blocking" :key="capability.key">
                    {{ capability.label }}<template v-if="capability.detail"> — {{ capability.detail }}</template>
                </li>
            </ul>
        </AppAlert>

        <section aria-labelledby="catalogue-heading">
            <h2 id="catalogue-heading" class="text-section-title text-foreground">
                {{ trans('ui.dashboard.catalogue') }}
            </h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                <MetricCard
                    v-for="key in CATALOGUE_ORDER"
                    :key="key"
                    :label="trans(`ui.dashboard.metric.${key}`)"
                    :value="snapshot.catalogue[key]"
                />
            </div>

            <!-- STO-005. Beside the asset count rather than instead of it.
                 The count answers "how many things"; this answers "how much
                 am I paying to keep them", and the second was answerable only
                 from a database client. -->
            <div class="mt-3">
                <StorageUsageCard :usage="snapshot.storage_usage" :locale="page.props.app.locale" />
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard>
                <template #header>{{ trans('ui.dashboard.tracks_by_status') }}</template>
                <EmptyState
                    v-if="occupied(snapshot.tracks_by_status).length === 0"
                    :title="trans('ui.dashboard.nothing_yet')"
                    :description="trans('ui.dashboard.nothing_yet_description')"
                />
                <dl v-else class="divide-y divide-border">
                    <div
                        v-for="[status, total] in occupied(snapshot.tracks_by_status)"
                        :key="status"
                        class="flex items-center justify-between py-2 first:pt-0 last:pb-0"
                    >
                        <dt><StatusBadge :status="status" group="generic" /></dt>
                        <dd class="numeric text-body text-foreground">{{ total }}</dd>
                    </div>
                </dl>
            </AppCard>

            <AppCard>
                <template #header>{{ trans('ui.dashboard.releases_by_status') }}</template>
                <EmptyState
                    v-if="occupied(snapshot.releases_by_status).length === 0"
                    :title="trans('ui.dashboard.nothing_yet')"
                    :description="trans('ui.dashboard.nothing_yet_description')"
                />
                <dl v-else class="divide-y divide-border">
                    <div
                        v-for="[status, total] in occupied(snapshot.releases_by_status)"
                        :key="status"
                        class="flex items-center justify-between py-2 first:pt-0 last:pb-0"
                    >
                        <dt><StatusBadge :status="status" group="generic" /></dt>
                        <dd class="numeric text-body text-foreground">{{ total }}</dd>
                    </div>
                </dl>
            </AppCard>

            <AppCard>
                <template #header>{{ trans('ui.dashboard.ingestion') }}</template>
                <EmptyState
                    v-if="occupied(snapshot.ingestion.candidates_by_status).length === 0"
                    :title="trans('ui.dashboard.nothing_yet')"
                    :description="trans('ui.dashboard.nothing_yet_description')"
                />
                <dl v-else class="divide-y divide-border">
                    <div
                        v-for="[status, total] in occupied(snapshot.ingestion.candidates_by_status)"
                        :key="status"
                        class="flex items-center justify-between py-2 first:pt-0 last:pb-0"
                    >
                        <dt><StatusBadge :status="status" group="generic" /></dt>
                        <dd class="numeric text-body text-foreground">{{ total }}</dd>
                    </div>
                </dl>
            </AppCard>

            <AppCard>
                <template #header>{{ trans('ui.dashboard.distribution') }}</template>
                <EmptyState
                    v-if="occupied(snapshot.distribution.deliveries_by_status).length === 0"
                    :title="trans('ui.dashboard.nothing_yet')"
                    :description="trans('ui.dashboard.nothing_yet_description')"
                />
                <dl v-else class="divide-y divide-border">
                    <div
                        v-for="[status, total] in occupied(snapshot.distribution.deliveries_by_status)"
                        :key="status"
                        class="flex items-center justify-between py-2 first:pt-0 last:pb-0"
                    >
                        <dt><StatusBadge :status="status" group="generic" /></dt>
                        <dd class="numeric text-body text-foreground">{{ total }}</dd>
                    </div>
                </dl>
            </AppCard>
        </div>

        <section aria-labelledby="operations-heading">
            <h2 id="operations-heading" class="text-section-title text-foreground">
                {{ trans('ui.dashboard.operations') }}
            </h2>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard :label="trans('ui.dashboard.metric.jobs_pending')" :value="snapshot.jobs.pending" />
                <MetricCard :label="trans('ui.dashboard.metric.jobs_failed')" :value="snapshot.jobs.failed" />
                <MetricCard :label="trans('ui.dashboard.metric.analyses')" :value="snapshot.media.analyses" />
                <MetricCard :label="trans('ui.dashboard.metric.analyses_failed')" :value="snapshot.media.failed" />
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <AppCard>
                <template #header>{{ trans('ui.dashboard.providers') }}</template>
                <ul class="divide-y divide-border">
                    <li
                        v-for="provider in providers"
                        :key="`${provider.subsystem}-${provider.name ?? 'unknown'}`"
                        class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-body text-foreground">{{ provider.name ?? trans('ui.states.unknown') }}</p>
                            <p class="text-caption text-muted">{{ provider.subsystem }}</p>
                        </div>
                        <StatusBadge :status="availability(provider.available)" group="generic" />
                    </li>
                </ul>
            </AppCard>

            <AppCard>
                <template #header>{{ trans('ui.dashboard.storage') }}</template>
                <div class="flex items-center justify-between gap-3 pb-2">
                    <p class="text-body text-foreground">{{ operational.storage.provider ?? trans('ui.states.unknown') }}</p>
                    <StatusBadge :status="availability(operational.storage.healthy)" group="generic" />
                </div>
                <dl class="divide-y divide-border border-t border-border">
                    <div
                        v-for="(passed, check) in operational.storage.checks"
                        :key="check"
                        class="flex items-center justify-between py-2 last:pb-0"
                    >
                        <dt class="text-small text-muted">{{ check }}</dt>
                        <dd><StatusBadge :status="availability(passed)" group="generic" /></dd>
                    </div>
                </dl>
                <p v-if="operational.storage.detail" class="pt-2 text-caption text-muted">
                    {{ operational.storage.detail }}
                </p>
            </AppCard>
        </div>

        <AppCard>
            <template #header>{{ trans('ui.dashboard.capabilities') }}</template>
            <ul class="divide-y divide-border">
                <li
                    v-for="capability in capabilities"
                    :key="capability.key"
                    class="flex items-start justify-between gap-3 py-2 first:pt-0 last:pb-0"
                >
                    <div class="min-w-0">
                        <p class="text-body text-foreground">{{ capability.label }}</p>
                        <p v-if="capability.detail" class="text-caption text-muted">{{ capability.detail }}</p>
                    </div>
                    <StatusBadge :status="capability.status.toUpperCase()" group="generic" />
                </li>
            </ul>
        </AppCard>
    </div>
</template>
