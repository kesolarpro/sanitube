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
import type { GenerationFilters, GenerationPage } from '@/Types/studio';

/**
 * Every request made to a generation provider.
 *
 * The prompt is the column, because it is the only thing that tells one
 * generation from another to a person. The lyrics are not: a verse in a table
 * cell is neither readable nor useful, and a list exists to find the row worth
 * opening.
 */
const props = defineProps<{ page: GenerationPage; filters: GenerationFilters; options: Record<string, string[]> }>();

const locale = usePage<SharedProps>().props.app.locale;

const status = ref(props.filters.status ?? '');
const rights = ref(props.filters.commercial_rights_status ?? '');

watch(
    () => props.filters,
    (filters) => {
        status.value = filters.status ?? '';
        rights.value = filters.commercial_rights_status ?? '';
    },
);

function currentQuery(): Record<string, string> {
    const query: Record<string, string> = {};

    if (status.value !== '') {
        query.status = status.value;
    }

    if (rights.value !== '') {
        query.commercial_rights_status = rights.value;
    }

    return query;
}

function apply(): void {
    router.get('/studio/generations', currentQuery(), { preserveState: true, preserveScroll: true, replace: true });
}

function cursorHref(cursor: string | null): string | null {
    return cursor === null
        ? null
        : `/studio/generations?${new URLSearchParams({ ...currentQuery(), cursor }).toString()}`;
}

const statusOptions = computed(() => [
    { value: '', label: trans('ui.studio.filter.any_status') },
    ...(props.options.status ?? []).map((value) => ({ value, label: trans(`ui.status.generic.${value}`) })),
]);

const rightsOptions = computed(() => [
    { value: '', label: trans('ui.studio.filter.any_rights') },
    ...(props.options.commercial_rights_status ?? []).map((value) => ({
        value,
        label: trans(`ui.status.generic.${value}`),
    })),
]);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.studio.generations') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.studio.generations_description') }}</p>
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.catalog.filters') }}</template>
            <form class="grid gap-3 p-4 sm:grid-cols-2" @submit.prevent="apply">
                <FormField :label="trans('ui.catalog.filter.status')">
                    <SelectInput v-model="status" :options="statusOptions" />
                </FormField>
                <FormField :label="trans('ui.studio.commercial_rights')">
                    <SelectInput v-model="rights" :options="rightsOptions" />
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
                :title="trans('ui.studio.no_generations')"
                :description="trans('ui.studio.no_generations_description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.studio.generations')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.studio.prompt') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.studio.provider') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.studio.commercial_rights') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.studio.result_count') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.ingestion.created_at') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/studio/generations/${row.uuid}`" class="hover:text-accent">
                                {{ row.prompt }}
                            </Link>
                            <span v-if="row.instrumental" class="ml-2 text-caption text-muted">
                                {{ trans('ui.studio.instrumental') }}
                            </span>
                        </TableCell>
                        <TableCell><StatusBadge :status="row.status" group="generic" /></TableCell>
                        <TableCell>{{ row.provider }}</TableCell>
                        <TableCell>
                            <span :class="row.commercial_rights_status === 'UNKNOWN' ? 'text-warning' : ''">
                                {{ trans(`ui.status.generic.${row.commercial_rights_status}`) }}
                            </span>
                        </TableCell>
                        <TableCell align="right" numeric>{{ row.result_count }}</TableCell>
                        <TableCell>{{ dateTime(row.created_at, locale) }}</TableCell>
                    </tr>
                </DataTable>
                <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
            </template>
        </AppCard>
    </div>
</template>
