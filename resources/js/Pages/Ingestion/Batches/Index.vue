<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import CursorPagination from '@/Components/Data/CursorPagination.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { dateTime } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { BatchFilters, BatchPage } from '@/Types/ingestion';

/**
 * Every import operation.
 *
 * The counts shown per row are the real ones, derived from the item rows on
 * the server. There is no percentage and no estimated finish time: both would
 * be invented, because a three-minute MP3 and an hour-long WAV take wildly
 * different amounts of time, and a progress bar that stalls at 94% teaches
 * people to ignore progress bars.
 */
const props = defineProps<{ page: BatchPage; filters: BatchFilters; options: Record<string, string[]> }>();

const locale = usePage<SharedProps>().props.app.locale;

const status = ref(props.filters.status ?? '');
const source = ref(props.filters.source ?? '');

watch(
    () => props.filters,
    (filters) => {
        status.value = filters.status ?? '';
        source.value = filters.source ?? '';
    },
);

function currentQuery(): Record<string, string> {
    const query: Record<string, string> = {};

    if (status.value !== '') {
        query.status = status.value;
    }

    if (source.value !== '') {
        query.source = source.value;
    }

    return query;
}

function apply(): void {
    router.get('/ingestion/batches', currentQuery(), { preserveState: true, preserveScroll: true, replace: true });
}

function cursorHref(cursor: string | null): string | null {
    return cursor === null ? null : `/ingestion/batches?${new URLSearchParams({ ...currentQuery(), cursor }).toString()}`;
}

const statusOptions = computed(() => [
    { value: '', label: trans('ui.ingestion.filter.any_status') },
    ...(props.options.status ?? []).map((value) => ({ value, label: trans(`ui.status.generic.${value}`) })),
]);

const sourceOptions = computed(() => [
    { value: '', label: trans('ui.ingestion.filter.any_source') },
    ...(props.options.source ?? []).map((value) => ({ value, label: trans(`ui.ingestion.source.${value}`) })),
]);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.ingestion.batches') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.ingestion.batches_description') }}</p>
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.catalog.filters') }}</template>
            <form class="grid gap-3 p-4 sm:grid-cols-2" @submit.prevent="apply">
                <FormField :label="trans('ui.catalog.filter.status')">
                    <SelectInput v-model="status" :options="statusOptions" />
                </FormField>
                <FormField :label="trans('ui.catalog.filter.source')">
                    <SelectInput v-model="source" :options="sourceOptions" />
                </FormField>
                <div class="sm:col-span-2">
                    <button
                        type="submit"
                        class="rounded-control bg-accent px-3 py-2 text-small font-medium text-accent-foreground hover:bg-accent-hover"
                    >
                        {{ trans('ui.catalog.apply') }}
                    </button>
                </div>
            </form>
        </AppCard>

        <AppCard :padded="false">
            <EmptyState
                v-if="page.rows.length === 0"
                :title="trans('ui.ingestion.no_batches')"
                :description="trans('ui.ingestion.no_batches_description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.ingestion.batches')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.ingestion.batch') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.filter.source') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.ingestion.total') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.status.generic.IMPORTED') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.status.generic.DUPLICATE') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.status.generic.FAILED') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.ingestion.started_at') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/ingestion/batches/${row.uuid}`" class="hover:text-accent">
                                {{ dateTime(row.created_at, locale) }}
                            </Link>
                        </TableCell>
                        <TableCell><StatusBadge :status="row.status" group="generic" /></TableCell>
                        <TableCell>{{ trans(`ui.ingestion.source.${row.source}`) }}</TableCell>
                        <TableCell align="right" numeric>{{ row.items.total }}</TableCell>
                        <TableCell align="right" numeric>{{ row.items.IMPORTED }}</TableCell>
                        <TableCell align="right" numeric>{{ row.items.DUPLICATE }}</TableCell>
                        <TableCell align="right" numeric>
                            <span :class="row.items.FAILED > 0 ? 'text-danger' : ''">{{ row.items.FAILED }}</span>
                        </TableCell>
                        <TableCell>{{ row.started_at === null ? trans('ui.ingestion.not_started') : dateTime(row.started_at, locale) }}</TableCell>
                    </tr>
                </DataTable>
                <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
            </template>
        </AppCard>
    </div>
</template>
