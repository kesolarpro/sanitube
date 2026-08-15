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
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { trans } from '@/Support/i18n';
import type { ArtistFilters, ArtistPage } from '@/Types/catalog';

/**
 * The artist list. Filters live in the URL, so a filtered list is shareable and
 * survives a refresh, and the cursor links can be plain hrefs.
 */
const props = defineProps<{ page: ArtistPage; filters: ArtistFilters; options: Record<string, string[]> }>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const type = ref(props.filters.type ?? '');

// The server's validated values are the source of truth: a back button or a
// cursor link replaces them, and the inputs must follow.
watch(
    () => props.filters,
    (filters) => {
        search.value = filters.search ?? '';
        status.value = filters.status ?? '';
        type.value = filters.type ?? '';
    },
);

function currentQuery(): Record<string, string> {
    const query: Record<string, string> = {};

    if (search.value.trim() !== '') {
        query.search = search.value.trim();
    }

    if (status.value !== '') {
        query.status = status.value;
    }

    if (type.value !== '') {
        query.type = type.value;
    }

    return query;
}

function apply(): void {
    // No cursor: a new filter is a new result set, and the old cursor would
    // open it somewhere in the middle.
    router.get('/catalog/artists', currentQuery(), { preserveState: true, preserveScroll: true, replace: true });
}

function cursorHref(cursor: string | null): string | null {
    return cursor === null ? null : `/catalog/artists?${new URLSearchParams({ ...currentQuery(), cursor }).toString()}`;
}

const statusOptions = computed(() => [
    { value: '', label: trans('ui.catalog.filter.any_status') },
    ...(props.options.status ?? []).map((value) => ({ value, label: trans(`ui.status.generic.${value}`) })),
]);

const typeOptions = computed(() => [
    { value: '', label: trans('ui.catalog.filter.any_type') },
    ...(props.options.type ?? []).map((value) => ({ value, label: trans(`ui.catalog.artist_type.${value}`) })),
]);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.catalog.artists') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.catalog.artists_description') }}</p>
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.catalog.filters') }}</template>
            <form class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="apply">
                <FormField :label="trans('ui.catalog.search')">
                    <TextInput v-model="search" type="search" :placeholder="trans('ui.catalog.search_artists')" />
                </FormField>
                <FormField :label="trans('ui.catalog.filter.status')">
                    <SelectInput v-model="status" :options="statusOptions" />
                </FormField>
                <FormField :label="trans('ui.catalog.filter.type')">
                    <SelectInput v-model="type" :options="typeOptions" />
                </FormField>
                <div class="sm:col-span-2 lg:col-span-3">
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
                :title="trans('ui.catalog.no_artists')"
                :description="trans('ui.catalog.no_artists_description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.catalog.artists')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.catalog.column.name') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.type') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.country') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.catalog.column.tracks') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.catalog.column.releases') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/catalog/artists/${row.uuid}`" class="text-foreground hover:text-accent">
                                {{ row.name }}
                            </Link>
                        </TableCell>
                        <TableCell>{{ trans(`ui.catalog.artist_type.${row.type}`) }}</TableCell>
                        <TableCell><StatusBadge :status="row.status" group="generic" /></TableCell>
                        <TableCell>{{ row.country ?? '—' }}</TableCell>
                        <TableCell align="right" numeric>{{ row.track_count }}</TableCell>
                        <TableCell align="right" numeric>{{ row.release_count }}</TableCell>
                    </tr>
                </DataTable>
                <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
            </template>
        </AppCard>
    </div>
</template>
