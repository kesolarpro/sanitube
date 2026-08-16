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
import type { DeliveryFilters, DeliveryPage } from '@/Types/distribution';

/**
 * Every handover this installation has made or attempted.
 *
 * "Last checked" is a column rather than a detail, and an unchecked delivery
 * says so rather than showing a dash. A delivery nobody has asked about is not
 * a delivery that has not moved — the distributor may have accepted it weeks
 * ago — and the two must not look the same in a list somebody scans.
 */
const props = defineProps<{ page: DeliveryPage; filters: DeliveryFilters; options: Record<string, string[]> }>();

const shared = usePage<SharedProps>();
const locale = shared.props.app.locale;

const status = ref(props.filters.status ?? '');
const provider = ref(props.filters.provider ?? '');

watch(
    () => props.filters,
    (filters) => {
        status.value = filters.status ?? '';
        provider.value = filters.provider ?? '';
    },
);

function currentQuery(): Record<string, string> {
    const query: Record<string, string> = {};

    if (status.value !== '') {
        query.status = status.value;
    }

    if (provider.value !== '') {
        query.provider = provider.value;
    }

    return query;
}

function apply(): void {
    router.get('/distribution', currentQuery(), { preserveState: true, preserveScroll: true, replace: true });
}

function cursorHref(cursor: string | null): string | null {
    return cursor === null ? null : `/distribution?${new URLSearchParams({ ...currentQuery(), cursor }).toString()}`;
}

const statusOptions = computed(() => [
    { value: '', label: trans('ui.distribution.filter.any_status') },
    ...(props.options.status ?? []).map((value) => ({ value, label: trans(`ui.status.generic.${value}`) })),
]);

const providerOptions = computed(() => [
    { value: '', label: trans('ui.distribution.filter.any_provider') },
    ...(props.options.provider ?? []).map((value) => ({ value, label: value })),
]);
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.distribution.title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.distribution.description') }}</p>
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.catalog.filters') }}</template>
            <form class="grid gap-3 p-4 sm:grid-cols-3" @submit.prevent="apply">
                <FormField :label="trans('ui.catalog.filter.status')">
                    <SelectInput v-model="status" :options="statusOptions" />
                </FormField>
                <FormField :label="trans('ui.distribution.provider')">
                    <SelectInput v-model="provider" :options="providerOptions" />
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
                :title="trans('ui.distribution.no_deliveries')"
                :description="trans('ui.distribution.no_deliveries_description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.distribution.title')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.catalog.column.releases') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.distribution.provider') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.distribution.attempts') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.distribution.submitted_at') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.distribution.last_synced_at') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/distribution/${row.uuid}`" class="hover:text-accent">
                                {{ row.release?.title ?? '—' }}
                            </Link>
                        </TableCell>
                        <TableCell>{{ row.provider }}</TableCell>
                        <TableCell><StatusBadge :status="row.status" group="generic" /></TableCell>
                        <TableCell>{{ row.attempt_count }}</TableCell>
                        <TableCell>
                            <span v-if="row.submitted_at === null" class="text-muted">
                                {{ trans('ui.distribution.not_reached_them') }}
                            </span>
                            <span v-else>{{ dateTime(row.submitted_at, locale) }}</span>
                        </TableCell>
                        <TableCell>
                            <!-- Never checked is a fact, not a blank. -->
                            <span v-if="row.last_synced_at === null" class="text-muted">
                                {{ trans('ui.distribution.never_synced') }}
                            </span>
                            <span v-else>{{ dateTime(row.last_synced_at, locale) }}</span>
                        </TableCell>
                    </tr>
                </DataTable>
                <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
            </template>
        </AppCard>
    </div>
</template>
