<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import MetricCard from '@/Components/Ui/MetricCard.vue';
import { dateTime } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { FailedJobRow, JobList, PendingJobRow, QueueSummary } from '@/Types/system';

/**
 * Work waiting, and work that broke.
 *
 * No payload and no stack trace arrives here — the server does not send them.
 * What is shown is the kind of work, the timestamps, and for a failure the
 * exception's first line, which is what says whether this is a provider outage
 * or a bug.
 */
defineProps<{
    summary: QueueSummary;
    pending: JobList<PendingJobRow>;
    failed: JobList<FailedJobRow>;
}>();

const locale = usePage<SharedProps>().props.app.locale;
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.system.jobs') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.system.jobs_description') }}</p>
        </div>

        <AppAlert v-if="!summary.readable" tone="info" :title="trans('ui.system.queue_unreadable')">
            {{ trans('ui.system.queue_unreadable_note') }}
        </AppAlert>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <MetricCard :label="trans('ui.system.queue_driver')" :value="null" :hint="summary.driver" />
            <MetricCard :label="trans('ui.system.pending')" :value="summary.pending" />
            <MetricCard :label="trans('ui.system.reserved')" :value="summary.reserved" />
            <MetricCard :label="trans('ui.system.failed')" :value="summary.failed" />
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.system.pending') }}</template>
            <EmptyState
                v-if="pending.rows.length === 0"
                :title="trans('ui.system.no_pending')"
                :description="trans('ui.states.empty_description')"
            />
            <DataTable v-else :caption="trans('ui.system.pending')">
                <template #head>
                    <TableHeaderCell>{{ trans('ui.system.job_kind') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.system.queue') }}</TableHeaderCell>
                    <TableHeaderCell align="right">{{ trans('ui.system.attempts') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.system.available_at') }}</TableHeaderCell>
                </template>
                <tr v-for="row in pending.rows" :key="row.id" class="border-t border-border">
                    <TableCell>
                        {{ row.name ?? trans('ui.system.job_unknown_kind') }}
                        <span v-if="row.reserved" class="ml-2 text-caption text-muted">
                            {{ trans('ui.system.reserved') }}
                        </span>
                    </TableCell>
                    <TableCell>{{ row.queue }}</TableCell>
                    <TableCell align="right" numeric>{{ row.attempts }}</TableCell>
                    <TableCell>{{ dateTime(row.available_at, locale) }}</TableCell>
                </tr>
            </DataTable>
        </AppCard>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.system.failed') }}</template>
            <EmptyState
                v-if="failed.rows.length === 0"
                :title="trans('ui.system.no_failed')"
                :description="trans('ui.states.empty_description')"
            />
            <DataTable v-else :caption="trans('ui.system.failed')">
                <template #head>
                    <TableHeaderCell>{{ trans('ui.system.job_kind') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.system.error') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.system.failed') }}</TableHeaderCell>
                </template>
                <tr v-for="row in failed.rows" :key="row.uuid" class="border-t border-border">
                    <TableCell>{{ row.name ?? trans('ui.system.job_unknown_kind') }}</TableCell>
                    <TableCell>
                        <span v-if="row.error !== null" class="text-small text-danger">{{ row.error }}</span>
                        <span v-else class="text-muted">{{ trans('ui.system.no_error') }}</span>
                    </TableCell>
                    <TableCell>{{ dateTime(row.failed_at, locale) }}</TableCell>
                </tr>
            </DataTable>
        </AppCard>
    </div>
</template>
