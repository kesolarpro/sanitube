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
import type { CompositionFilters, CompositionPage } from '@/Types/catalog';

const props = defineProps<{ page: CompositionPage; filters: CompositionFilters; options: Record<string, string[]> }>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

watch(
    () => props.filters,
    (filters) => {
        search.value = filters.search ?? '';
        status.value = filters.status ?? '';
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

    return query;
}

function apply(): void {
    router.get('/catalog/compositions', currentQuery(), { preserveState: true, preserveScroll: true, replace: true });
}

function cursorHref(cursor: string | null): string | null {
    return cursor === null
        ? null
        : `/catalog/compositions?${new URLSearchParams({ ...currentQuery(), cursor }).toString()}`;
}

const statusOptions = computed(() => [
    { value: '', label: trans('ui.catalog.filter.any_status') },
    ...(props.options.status ?? []).map((value) => ({ value, label: trans(`ui.status.generic.${value}`) })),
]);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.catalog.compositions') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.catalog.compositions_description') }}</p>
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.catalog.filters') }}</template>
            <form class="grid gap-3 p-4 sm:grid-cols-2" @submit.prevent="apply">
                <FormField :label="trans('ui.catalog.search')" :hint="trans('ui.catalog.search_compositions_hint')">
                    <TextInput v-model="search" type="search" :placeholder="trans('ui.catalog.search_compositions')" />
                </FormField>
                <FormField :label="trans('ui.catalog.filter.status')">
                    <SelectInput v-model="status" :options="statusOptions" />
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
                :title="trans('ui.catalog.no_compositions')"
                :description="trans('ui.catalog.no_compositions_description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.catalog.compositions')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.catalog.column.title') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.language') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.iswc') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.catalog.column.recordings') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/catalog/compositions/${row.uuid}`" class="text-foreground hover:text-accent">
                                {{ row.title }}
                            </Link>
                            <span v-if="row.is_public_domain" class="ml-1 text-caption text-muted">
                                {{ trans('ui.catalog.public_domain') }}
                            </span>
                        </TableCell>
                        <TableCell><StatusBadge :status="row.status" group="generic" /></TableCell>
                        <TableCell>{{ row.language_code }}</TableCell>
                        <TableCell>
                            <span v-if="row.iswc" class="font-mono text-identifier">{{ row.iswc }}</span>
                            <span v-else class="text-muted">—</span>
                        </TableCell>
                        <TableCell align="right" numeric>{{ row.track_count }}</TableCell>
                    </tr>
                </DataTable>
                <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
            </template>
        </AppCard>
    </div>
</template>
