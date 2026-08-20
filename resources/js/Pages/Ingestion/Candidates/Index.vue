<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import CursorPagination from '@/Components/Data/CursorPagination.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextareaInput from '@/Components/Ui/TextareaInput.vue';
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
 *
 * **BULK-002: deciding about many at once.** Nine hundred imported files make
 * nine hundred candidates, and before this the only way through them was nine
 * hundred page loads. Two things this deliberately does not do:
 *
 *   - **It never overrides.** Overriding is where a person overrules the
 *     machine about one recording they have looked at. Offering it for a
 *     hundred at a time is exactly what the override exists to prevent, so a
 *     candidate the analysis flagged is refused by name and stays in the queue.
 *   - **It never decides here.** Each selection queues one job per candidate.
 *     The queue survives this tab; a loop of a thousand transactions inside a
 *     request does not.
 */
const props = defineProps<{ page: CandidatePage; filters: CandidateFilters; options: Record<string, string[]> }>();

const locale = usePage<SharedProps>().props.app.locale;

/* ------------------------------------------------------------- selection */

const selected = ref<Set<string>>(new Set());
const confirming = ref<'promote' | 'reject' | null>(null);
const reason = ref('');
const submitting = ref(false);

const refusal = computed(() => {
    const errors = usePage<SharedProps>().props.errors as Record<string, string> | undefined;

    return errors?.review ?? null;
});

/** Only what is on this page. A selection cannot mean rows nobody has seen. */
const allOnPageSelected = computed(
    () => props.page.rows.length > 0 && props.page.rows.every((row) => selected.value.has(row.uuid)),
);

function toggle(uuid: string): void {
    // Replaced rather than mutated: a Set mutated in place does not re-render.
    const next = new Set(selected.value);

    next.has(uuid) ? next.delete(uuid) : next.add(uuid);
    selected.value = next;
}

function toggleAllOnPage(): void {
    const next = new Set(selected.value);

    for (const row of props.page.rows) {
        allOnPageSelected.value ? next.delete(row.uuid) : next.add(row.uuid);
    }

    selected.value = next;
}

const reasonIsUsable = computed(() => reason.value.trim().length >= 5);

function decide(): void {
    if (confirming.value === null) {
        return;
    }

    submitting.value = true;

    router.post(
        '/ingestion/candidates/bulk',
        {
            candidates: [...selected.value],
            decision: confirming.value,
            reason: confirming.value === 'reject' ? reason.value : null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = new Set();
                reason.value = '';
            },
            onFinish: () => {
                submitting.value = false;
                confirming.value = null;
            },
        },
    );
}

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

        <AppAlert v-if="refusal !== null" tone="danger" :title="trans(`ui.ingestion.bulk_refusal.${refusal}`)" />

        <!-- BULK-002. Present only when something is selected, so the ordinary
             one-at-a-time review is not surrounded by controls it does not
             need. -->
        <AppCard v-if="selected.size > 0">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-small text-foreground">
                    {{ trans('ui.ingestion.selected_count', { count: String(selected.size) }) }}
                </p>
                <span class="flex flex-wrap gap-2">
                    <AppButton variant="ghost" @click="selected = new Set()">
                        {{ trans('ui.ingestion.clear_selection') }}
                    </AppButton>
                    <AppButton variant="ghost" @click="confirming = 'reject'">
                        {{ trans('ui.ingestion.reject_selected') }}
                    </AppButton>
                    <AppButton variant="primary" @click="confirming = 'promote'">
                        {{ trans('ui.ingestion.promote_selected') }}
                    </AppButton>
                </span>
            </div>
            <p class="mt-2 text-caption text-muted">{{ trans('ui.ingestion.bulk_note') }}</p>
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
                        <TableHeaderCell>
                            <input
                                type="checkbox"
                                class="size-4 rounded border-border"
                                :checked="allOnPageSelected"
                                :aria-label="trans('ui.ingestion.select_all_on_page')"
                                @change="toggleAllOnPage"
                            />
                        </TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.ingestion.suggested_title') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.ingestion.filename') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.filter.source') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.ingestion.what_we_noticed') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.ingestion.created_at') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <input
                                type="checkbox"
                                class="size-4 rounded border-border"
                                :checked="selected.has(row.uuid)"
                                :aria-label="row.suggested_title ?? row.original_filename"
                                @change="toggle(row.uuid)"
                            />
                        </TableCell>
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

        <ConfirmDialog
            :open="confirming !== null"
            :title="confirming === 'reject' ? trans('ui.ingestion.reject_selected') : trans('ui.ingestion.promote_selected')"
            :description="
                confirming === 'reject'
                    ? trans('ui.ingestion.reject_selected_description', { count: String(selected.size) })
                    : trans('ui.ingestion.promote_selected_description', { count: String(selected.size) })
            "
            :confirm-label="trans('ui.actions.confirm')"
            :busy="submitting"
            :destructive="confirming === 'reject'"
            @cancel="confirming = null"
            @confirm="confirming === 'reject' && !reasonIsUsable ? undefined : decide()"
        >
            <template v-if="confirming === 'reject'">
                <FormField :label="trans('ui.ingestion.reject_reason')">
                    <TextareaInput v-model="reason" :rows="3" />
                </FormField>
                <p v-if="!reasonIsUsable" class="mt-2 text-small text-muted">
                    {{ trans('ui.ingestion.reject_reason_required') }}
                </p>
            </template>
        </ConfirmDialog>
    </div>
</template>
