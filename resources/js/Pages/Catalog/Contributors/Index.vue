<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import CursorPagination from '@/Components/Data/CursorPagination.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { trans } from '@/Support/i18n';
import type { ContributorFilters, ContributorPage } from '@/Types/catalog';

/** Filters live in the URL, so a filtered list is shareable and survives a refresh. */
const props = defineProps<{ page: ContributorPage; filters: ContributorFilters; options: Record<string, string[]> }>();

const search = ref(props.filters.search ?? '');
const type = ref(props.filters.type ?? '');

watch(
    () => props.filters,
    (filters) => {
        search.value = filters.search ?? '';
        type.value = filters.type ?? '';
    },
);

function currentQuery(): Record<string, string> {
    const query: Record<string, string> = {};

    if (search.value.trim() !== '') {
        query.search = search.value.trim();
    }

    if (type.value !== '') {
        query.type = type.value;
    }

    return query;
}

function apply(): void {
    // No cursor: a new filter is a new result set.
    router.get('/catalog/contributors', currentQuery(), { preserveState: true, preserveScroll: true, replace: true });
}

function cursorHref(cursor: string | null): string | null {
    return cursor === null
        ? null
        : `/catalog/contributors?${new URLSearchParams({ ...currentQuery(), cursor }).toString()}`;
}

const typeOptions = computed(() => [
    { value: '', label: trans('ui.catalog.filter.any_type') },
    ...(props.options.type ?? []).map((value) => ({ value, label: trans(`ui.catalog.contributor_type.${value}`) })),
]);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.catalog.contributors') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.catalog.contributors_description') }}</p>
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.catalog.filters') }}</template>
            <form class="grid gap-3 p-4 sm:grid-cols-2" @submit.prevent="apply">
                <FormField :label="trans('ui.catalog.search')">
                    <TextInput v-model="search" type="search" :placeholder="trans('ui.catalog.search_contributors')" />
                </FormField>
                <FormField :label="trans('ui.catalog.filter.type')">
                    <SelectInput v-model="type" :options="typeOptions" />
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
                :title="trans('ui.catalog.no_contributors')"
                :description="trans('ui.catalog.no_contributors_description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.catalog.contributors')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.catalog.column.name') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.type') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.country') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.catalog.column.compositions') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.catalog.column.tracks') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/catalog/contributors/${row.uuid}`" class="text-foreground hover:text-accent">
                                {{ row.name }}
                            </Link>
                        </TableCell>
                        <TableCell>{{ trans(`ui.catalog.contributor_type.${row.type}`) }}</TableCell>
                        <TableCell>{{ row.country ?? '—' }}</TableCell>
                        <TableCell align="right" numeric>{{ row.composition_count }}</TableCell>
                        <TableCell align="right" numeric>{{ row.track_count }}</TableCell>
                    </tr>
                </DataTable>
                <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
            </template>
        </AppCard>
    </div>
</template>
