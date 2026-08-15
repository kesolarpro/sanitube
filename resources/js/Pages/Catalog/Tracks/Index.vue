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
import { duration } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { TrackFilters, TrackPage } from '@/Types/catalog';

/**
 * The track list.
 *
 * Filters live in the URL rather than in component state. That is what makes a
 * filtered list shareable, bookmarkable and survivable across a browser
 * refresh — and it is what lets the cursor links below be plain hrefs instead
 * of a second, parallel notion of "where am I".
 */
const props = defineProps<{ page: TrackPage; filters: TrackFilters; options: Record<string, string[]> }>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const source = ref(props.filters.source ?? '');
const instrumental = ref(props.filters.instrumental ?? '');

/*
 * Server-side props are the single source of truth: when a navigation replaces
 * them — the back button, a cursor link — the inputs follow. Without this the
 * form would keep showing the filters from the page the reader just left.
 */
watch(
    () => props.filters,
    (filters) => {
        search.value = filters.search ?? '';
        status.value = filters.status ?? '';
        source.value = filters.source ?? '';
        instrumental.value = filters.instrumental ?? '';
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

    if (source.value !== '') {
        query.source = source.value;
    }

    if (instrumental.value !== '') {
        query.instrumental = instrumental.value;
    }

    return query;
}

function apply(): void {
    // No cursor: a new filter means a new result set, and carrying the old
    // cursor forward would open the list somewhere in the middle of it.
    router.get('/catalog/tracks', currentQuery(), { preserveState: true, preserveScroll: true, replace: true });
}

function cursorHref(cursor: string | null): string | null {
    if (cursor === null) {
        return null;
    }

    const query = new URLSearchParams({ ...currentQuery(), cursor });

    return `/catalog/tracks?${query.toString()}`;
}

const statusOptions = computed(() => [
    { value: '', label: trans('ui.catalog.filter.any_status') },
    ...(props.options.status ?? []).map((value) => ({ value, label: trans(`ui.status.generic.${value}`) })),
]);

const sourceOptions = computed(() => [
    { value: '', label: trans('ui.catalog.filter.any_source') },
    ...(props.options.source ?? []).map((value) => ({ value, label: trans(`ui.catalog.source.${value}`) })),
]);

const instrumentalOptions = computed(() => [
    { value: '', label: trans('ui.catalog.filter.any_content') },
    { value: '1', label: trans('ui.catalog.instrumental') },
    { value: '0', label: trans('ui.catalog.vocal') },
]);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.catalog.tracks') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.catalog.tracks_description') }}</p>
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.catalog.filters') }}</template>

            <form class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="apply">
                <FormField :label="trans('ui.catalog.search')" :hint="trans('ui.catalog.search_hint')">
                    <TextInput v-model="search" type="search" :placeholder="trans('ui.catalog.search_placeholder')" />
                </FormField>

                <FormField :label="trans('ui.catalog.filter.status')">
                    <SelectInput v-model="status" :options="statusOptions" />
                </FormField>

                <FormField :label="trans('ui.catalog.filter.source')">
                    <SelectInput v-model="source" :options="sourceOptions" />
                </FormField>

                <FormField :label="trans('ui.catalog.filter.content')">
                    <SelectInput v-model="instrumental" :options="instrumentalOptions" />
                </FormField>

                <div class="sm:col-span-2 lg:col-span-4">
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
                :title="trans('ui.catalog.no_tracks')"
                :description="trans('ui.catalog.no_tracks_description')"
            />

            <template v-else>
                <DataTable :caption="trans('ui.catalog.tracks')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.catalog.column.title') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.artists') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.language') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.catalog.column.duration') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.isrc') }}</TableHeaderCell>
                    </template>

                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/catalog/tracks/${row.uuid}`" class="text-foreground hover:text-accent">
                                {{ row.title }}
                            </Link>
                            <span v-if="row.version_title" class="text-muted"> ({{ row.version_title }})</span>
                            <span v-if="row.is_explicit" class="ml-1 text-caption text-danger">{{ trans('ui.catalog.explicit') }}</span>
                        </TableCell>
                        <TableCell>
                            <span v-if="row.artists.length === 0" class="text-muted">—</span>
                            <span v-else>{{ row.artists.map((artist) => artist.name).join(', ') }}</span>
                        </TableCell>
                        <TableCell><StatusBadge :status="row.status" group="generic" /></TableCell>
                        <TableCell>{{ row.language_code }}</TableCell>
                        <TableCell align="right" numeric>{{ duration(row.duration_ms) }}</TableCell>
                        <TableCell>
                            <span v-if="row.isrc" class="font-mono text-identifier">{{ row.isrc }}</span>
                            <span v-else class="text-muted">—</span>
                        </TableCell>
                    </tr>
                </DataTable>

                <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
            </template>
        </AppCard>

    </div>
</template>
