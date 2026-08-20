<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import CursorPagination from '@/Components/Data/CursorPagination.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { bytes, dateTime, duration } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { DuplicateFilters, DuplicatePage, DuplicateRow, DuplicateSide } from '@/Types/deduplication';

/**
 * The duplicate queue.
 *
 * **Both files are shown side by side, with their evidence.** A row that said
 * only "these two match" would send the reviewer to two other screens before
 * they could answer, and a queue costing three page loads per decision is one
 * nobody works through.
 *
 * **Agreeing and discarding are two buttons, never one.** Confirming says the
 * platform was right; setting a master aside is a separate act with its own
 * reason. A single control that did both would quietly make an agreement into
 * a deletion — which is exactly what this feature must never become.
 *
 * A measured finding and a proposed one are labelled differently on purpose:
 * identical bytes are a fact, and 93% agreement is the platform's opinion.
 */
const props = defineProps<{ page: DuplicatePage; filters: DuplicateFilters; options: Record<string, string[]> }>();

const shared = usePage<SharedProps>().props;
const locale = shared.app.locale;

/**
 * Presentation only. The route middleware is the authorisation — a hidden
 * button is not a permission check.
 */
const mayDecide = computed(() => shared.auth.user?.can.catalogue === true);

const decision = ref(props.filters.decision ?? '');
const level = ref(props.filters.level ?? '');
const reasons = ref<Record<string, string>>({});

watch(
    () => props.filters,
    (filters) => {
        decision.value = filters.decision ?? '';
        level.value = filters.level ?? '';
    },
);

function currentQuery(): Record<string, string> {
    const query: Record<string, string> = {};

    if (decision.value !== '') {
        query.decision = decision.value;
    }

    if (level.value !== '') {
        query.level = level.value;
    }

    return query;
}

function apply(): void {
    router.get('/duplicates', currentQuery(), { preserveState: true, preserveScroll: true, replace: true });
}

function cursorHref(cursor: string | null): string | null {
    return cursor === null ? null : `/duplicates?${new URLSearchParams({ ...currentQuery(), cursor }).toString()}`;
}

const decisionOptions = computed(() => [
    { value: '', label: trans('ui.duplicates.filter.any_decision') },
    ...(props.options.decision ?? []).map((value) => ({ value, label: trans(`ui.duplicates.decision.${value}`) })),
]);

const levelOptions = computed(() => [
    { value: '', label: trans('ui.duplicates.filter.any_level') },
    ...(props.options.level ?? []).map((value) => ({ value, label: trans(`ui.duplicates.level.${value}`) })),
]);

const reasonOptions = computed(() =>
    (props.options.trash_reason ?? []).map((value) => ({ value, label: trans(`ui.duplicates.reason.${value}`) })),
);

function reasonFor(uuid: string): string {
    return reasons.value[uuid] ?? props.options.trash_reason?.[0] ?? 'OTHER';
}

function decide(row: DuplicateRow, verdict: 'confirm' | 'reject'): void {
    router.post(`/duplicates/${row.uuid}/${verdict}`, {}, { preserveScroll: true });
}

function trash(side: DuplicateSide): void {
    router.post(`/assets/${side.uuid}/trash`, { reason: reasonFor(side.uuid) }, { preserveScroll: true });
}

function restore(side: DuplicateSide): void {
    router.post(`/assets/${side.uuid}/restore`, {}, { preserveScroll: true });
}

/**
 * Null means no acoustic comparison happened, which is not the same as no
 * agreement — so it renders as a dash rather than as a percentage.
 */
function agreement(value: number | null): string {
    return value === null ? '—' : `${(value * 100).toFixed(1)}%`;
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.duplicates.title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.duplicates.description') }}</p>
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.catalog.filters') }}</template>
            <form class="grid gap-3 p-4 sm:grid-cols-3" @submit.prevent="apply">
                <FormField :label="trans('ui.duplicates.column.decision')">
                    <SelectInput v-model="decision" :options="decisionOptions" />
                </FormField>
                <FormField :label="trans('ui.duplicates.column.level')">
                    <SelectInput v-model="level" :options="levelOptions" />
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

        <EmptyState
            v-if="page.rows.length === 0"
            :title="trans('ui.duplicates.empty')"
            :description="trans('ui.duplicates.empty_description')"
        />

        <template v-else>
            <AppCard v-for="row in page.rows" :key="row.uuid" :padded="false">
                <template #header>
                    <div class="flex flex-wrap items-center gap-3">
                        <StatusBadge :status="row.decision" group="generic" />
                        <span class="text-small font-medium text-foreground">
                            {{ trans(`ui.duplicates.level.${row.level}`) }}
                        </span>
                        <span class="text-small text-muted">
                            {{
                                row.is_measured
                                    ? trans('ui.duplicates.measured')
                                    : trans('ui.duplicates.proposed')
                            }}
                        </span>
                    </div>
                </template>

                <div class="grid gap-4 p-4 md:grid-cols-2">
                    <section
                        v-for="side in [row.asset, row.related_asset].filter((s): s is DuplicateSide => s !== null)"
                        :key="side.uuid"
                        class="space-y-2 rounded-control border border-border p-3"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <CodeValue :value="side.checksum_short" />
                            <StatusBadge :status="side.status" group="generic" />
                        </div>

                        <dl class="grid grid-cols-2 gap-1 text-small text-muted">
                            <dt>{{ trans('ui.duplicates.column.type') }}</dt>
                            <dd>{{ side.mime_type ?? '—' }}</dd>
                            <dt>{{ trans('ui.duplicates.column.size') }}</dt>
                            <dd>{{ bytes(side.byte_size) }}</dd>
                            <dt>{{ trans('ui.duplicates.column.duration') }}</dt>
                            <dd>{{ duration(side.duration_ms) }}</dd>
                            <dt>{{ trans('ui.duplicates.column.added') }}</dt>
                            <dd>{{ dateTime(side.created_at, locale) }}</dd>
                        </dl>

                        <p v-if="side.is_trashed" class="text-small text-warning">
                            {{ trans('ui.duplicates.in_trash') }}
                        </p>

                        <div v-if="mayDecide" class="flex flex-wrap items-end gap-2">
                            <template v-if="side.is_trashed">
                                <button
                                    type="button"
                                    class="rounded-control border border-border px-3 py-1.5 text-small hover:bg-surface-hover"
                                    @click="restore(side)"
                                >
                                    {{ trans('ui.duplicates.action.restore') }}
                                </button>
                            </template>
                            <template v-else>
                                <FormField :label="trans('ui.duplicates.column.reason')">
                                    <SelectInput
                                        :model-value="reasonFor(side.uuid)"
                                        :options="reasonOptions"
                                        @update:model-value="(value: string | null | undefined) => (reasons[side.uuid] = value ?? 'OTHER')"
                                    />
                                </FormField>
                                <button
                                    type="button"
                                    class="rounded-control border border-danger px-3 py-1.5 text-small text-danger hover:bg-surface-hover"
                                    @click="trash(side)"
                                >
                                    {{ trans('ui.duplicates.action.trash') }}
                                </button>
                            </template>
                        </div>
                    </section>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border p-4">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-small text-muted sm:grid-cols-4">
                        <dt>{{ trans('ui.duplicates.column.agreement') }}</dt>
                        <dd>{{ agreement(row.similarity) }}</dd>
                        <dt>{{ trans('ui.duplicates.column.basis') }}</dt>
                        <dd>{{ trans(`ui.duplicates.basis.${row.basis}`) }}</dd>
                        <dt>{{ trans('ui.duplicates.column.found') }}</dt>
                        <dd>{{ dateTime(row.detected_at, locale) }}</dd>
                        <dt>{{ trans('ui.duplicates.column.answered') }}</dt>
                        <dd>{{ row.decided_at === null ? '—' : dateTime(row.decided_at, locale) }}</dd>
                    </dl>

                    <div v-if="mayDecide" class="flex gap-2">
                        <button
                            type="button"
                            class="rounded-control bg-accent px-3 py-2 text-small font-medium text-accent-foreground hover:bg-accent-hover"
                            @click="decide(row, 'confirm')"
                        >
                            {{ trans('ui.duplicates.action.confirm') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-control border border-border px-3 py-2 text-small hover:bg-surface-hover"
                            @click="decide(row, 'reject')"
                        >
                            {{ trans('ui.duplicates.action.reject') }}
                        </button>
                    </div>
                </div>
            </AppCard>

            <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
        </template>
    </div>
</template>
