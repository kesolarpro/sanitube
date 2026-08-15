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
import type { CandidateFilters, CandidatePage } from '@/Types/ingestion';

/**
 * The review queue.
 *
 * The column is `suggested_title` and it is labelled as one. A column headed
 * "Title" beside the catalogue's own lists would be read as a catalogue title,
 * and nothing on this screen is in the catalogue.
 */
const props = defineProps<{ page: CandidatePage; filters: CandidateFilters; options: Record<string, string[]> }>();

const locale = usePage<SharedProps>().props.app.locale;

const status = ref(props.filters.status ?? '');
const source = ref(props.filters.source ?? '');
const awaiting = ref(props.filters.awaiting_review ?? '');

watch(
    () => props.filters,
    (filters) => {
        status.value = filters.status ?? '';
        source.value = filters.source ?? '';
        awaiting.value = filters.awaiting_review ?? '';
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

    if (awaiting.value !== '') {
        query.awaiting_review = awaiting.value;
    }

    return query;
}

function apply(): void {
    router.get('/ingestion/candidates', currentQuery(), { preserveState: true, preserveScroll: true, replace: true });
}

function cursorHref(cursor: string | null): string | null {
    return cursor === null
        ? null
        : `/ingestion/candidates?${new URLSearchParams({ ...currentQuery(), cursor }).toString()}`;
}

const statusOptions = computed(() => [
    { value: '', label: trans('ui.ingestion.filter.any_status') },
    ...(props.options.status ?? []).map((value) => ({ value, label: trans(`ui.status.generic.${value}`) })),
]);

const sourceOptions = computed(() => [
    { value: '', label: trans('ui.ingestion.filter.any_source') },
    ...(props.options.source ?? []).map((value) => ({ value, label: trans(`ui.ingestion.source.${value}`) })),
]);

const awaitingOptions = computed(() => [
    { value: '', label: trans('ui.ingestion.filter.all_candidates') },
    { value: '1', label: trans('ui.ingestion.awaiting_review') },
]);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.ingestion.candidates') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.ingestion.candidates_description') }}</p>
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.catalog.filters') }}</template>
            <form class="grid gap-3 p-4 sm:grid-cols-3" @submit.prevent="apply">
                <FormField :label="trans('ui.catalog.filter.status')">
                    <SelectInput v-model="status" :options="statusOptions" />
                </FormField>
                <FormField :label="trans('ui.catalog.filter.source')">
                    <SelectInput v-model="source" :options="sourceOptions" />
                </FormField>
                <FormField :label="trans('ui.ingestion.awaiting_review')">
                    <SelectInput v-model="awaiting" :options="awaitingOptions" />
                </FormField>
                <div class="sm:col-span-3">
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
                :title="trans('ui.ingestion.no_candidates')"
                :description="trans('ui.ingestion.no_candidates_description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.ingestion.candidates')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.ingestion.suggested_title') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.ingestion.filename') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.filter.source') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.ingestion.what_we_noticed') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.ingestion.created_at') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/ingestion/candidates/${row.uuid}`" class="hover:text-accent">
                                {{ row.suggested_title ?? '—' }}
                            </Link>
                        </TableCell>
                        <TableCell>{{ row.original_filename }}</TableCell>
                        <TableCell><StatusBadge :status="row.status" group="generic" /></TableCell>
                        <TableCell>{{ trans(`ui.ingestion.source.${row.source}`) }}</TableCell>
                        <TableCell>
                            <span v-if="row.has_conflicts" class="text-small text-danger">
                                {{ trans('ui.ingestion.conflict') }}
                            </span>
                            <span v-else-if="row.is_duplicate" class="text-small text-warning">
                                {{ trans('ui.status.generic.DUPLICATE') }}
                            </span>
                            <span v-else class="text-muted">—</span>
                        </TableCell>
                        <TableCell>{{ dateTime(row.created_at, locale) }}</TableCell>
                    </tr>
                </DataTable>
                <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
            </template>
        </AppCard>
    </div>
</template>
