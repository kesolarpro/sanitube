<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue';
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

const page = usePage<SharedProps>();
const locale = page.props.app.locale;

/**
 * Acting on a failure.
 *
 * A retry button on every row would offer one on the rows where running the
 * work twice is exactly what must not happen. The job declares whether a
 * re-run converges; the screen only shows what the job said, and the server
 * refuses regardless of what was shown.
 *
 * Deleting removes the *failure record*, not the work — the wording says so,
 * because a button called "delete" beside a failed job reads like undoing.
 */
const acting = ref<string | null>(null);
const forgetting = ref<FailedJobRow | null>(null);

const refusal = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.jobs ?? null;
});

function retry(row: FailedJobRow): void {
    acting.value = row.uuid;

    router.post(`/system/jobs/failed/${row.uuid}/retry`, {}, {
        preserveScroll: true,
        onFinish: () => {
            acting.value = null;
        },
    });
}

function forget(): void {
    const row = forgetting.value;

    if (row === null) {
        return;
    }

    acting.value = row.uuid;

    router.post(`/system/jobs/failed/${row.uuid}/forget`, {}, {
        preserveScroll: true,
        onFinish: () => {
            acting.value = null;
            forgetting.value = null;
        },
    });
}
</script>

<template>
    <div class="space-y-4">
        <AppAlert v-if="refusal !== null" tone="danger" :title="trans('ui.ingestion.refused')">
            {{ trans(`ui.system.job_failure.${refusal}`) }}
        </AppAlert>

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
                    <TableHeaderCell align="right">{{ trans('ui.system.job_actions') }}</TableHeaderCell>
                </template>
                <tr v-for="row in failed.rows" :key="row.uuid" class="border-t border-border">
                    <TableCell>{{ row.name ?? trans('ui.system.job_unknown_kind') }}</TableCell>
                    <TableCell>
                        <span v-if="row.error !== null" class="text-small text-danger">{{ row.error }}</span>
                        <span v-else class="text-muted">{{ trans('ui.system.no_error') }}</span>
                    </TableCell>
                    <TableCell>{{ dateTime(row.failed_at, locale) }}</TableCell>
                    <TableCell align="right">
                        <div class="flex justify-end gap-2">
                            <AppButton
                                v-if="row.retryable"
                                variant="secondary"
                                :loading="acting === row.uuid"
                                @click="retry(row)"
                            >
                                {{ trans('ui.system.retry_job') }}
                            </AppButton>
                            <span v-else class="self-center text-caption text-muted">
                                {{ trans('ui.system.not_retryable') }}
                            </span>
                            <AppButton variant="ghost" @click="forgetting = row">
                                {{ trans('ui.system.forget_job') }}
                            </AppButton>
                        </div>
                    </TableCell>
                </tr>
            </DataTable>
        </AppCard>

        <ConfirmDialog
            :open="forgetting !== null"
            :title="trans('ui.system.forget_job')"
            :description="trans('ui.system.forget_job_description')"
            :confirm-label="trans('ui.system.forget_job')"
            destructive
            :busy="acting !== null"
            @confirm="forget"
            @cancel="forgetting = null"
        />
    </div>
</template>
