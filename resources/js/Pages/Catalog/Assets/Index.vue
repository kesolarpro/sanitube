<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import CursorPagination from '@/Components/Data/CursorPagination.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { bytes, duration } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { AssetFilters, AssetPage } from '@/Types/catalog';

const props = defineProps<{ page: AssetPage; filters: AssetFilters; options: Record<string, string[]> }>();

const kind = ref(props.filters.kind ?? '');
const status = ref(props.filters.status ?? '');

watch(
    () => props.filters,
    (filters) => {
        kind.value = filters.kind ?? '';
        status.value = filters.status ?? '';
    },
);

function currentQuery(): Record<string, string> {
    const query: Record<string, string> = {};

    if (kind.value !== '') {
        query.kind = kind.value;
    }

    if (status.value !== '') {
        query.status = status.value;
    }

    return query;
}

function apply(): void {
    router.get('/catalog/assets', currentQuery(), { preserveState: true, preserveScroll: true, replace: true });
}

function cursorHref(cursor: string | null): string | null {
    return cursor === null ? null : `/catalog/assets?${new URLSearchParams({ ...currentQuery(), cursor }).toString()}`;
}

const kindOptions = computed(() => [
    { value: '', label: trans('ui.catalog.filter.any_kind') },
    ...(props.options.kind ?? []).map((value) => ({ value, label: trans(`ui.catalog.asset_kind.${value}`) })),
]);

const statusOptions = computed(() => [
    { value: '', label: trans('ui.catalog.filter.any_status') },
    ...(props.options.status ?? []).map((value) => ({ value, label: trans(`ui.status.generic.${value}`) })),
]);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.catalog.assets') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.catalog.assets_description') }}</p>
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.catalog.filters') }}</template>
            <form class="grid gap-3 p-4 sm:grid-cols-2" @submit.prevent="apply">
                <FormField :label="trans('ui.catalog.filter.kind')">
                    <SelectInput v-model="kind" :options="kindOptions" />
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
                :title="trans('ui.catalog.no_assets')"
                :description="trans('ui.catalog.no_assets_description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.catalog.assets')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.catalog.column.checksum') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.kind') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.catalog.column.size') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.catalog.column.duration') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/catalog/assets/${row.uuid}`" class="hover:text-accent">
                                <CodeValue :value="row.checksum_short" />
                            </Link>
                            <span v-if="row.is_duplicate" class="ml-2 text-caption text-warning">
                                {{ trans('ui.catalog.duplicate') }}
                            </span>
                        </TableCell>
                        <TableCell>{{ trans(`ui.catalog.asset_kind.${row.kind}`) }}</TableCell>
                        <TableCell><StatusBadge :status="row.status" group="generic" /></TableCell>
                        <TableCell align="right" numeric>{{ bytes(row.byte_size) }}</TableCell>
                        <TableCell align="right" numeric>{{ duration(row.duration_ms) }}</TableCell>
                    </tr>
                </DataTable>
                <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
            </template>
        </AppCard>
    </div>
</template>
