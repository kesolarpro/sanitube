<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import CursorPagination from '@/Components/Data/CursorPagination.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { dateTime } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { AuditFilters, AuditOptions, AuditPage, AuditRow } from '@/Types/system';

/**
 * What has been done here.
 *
 * **Refusals are shown, not hidden.** A log of everything that worked
 * describes a well-behaved installation and says nothing about the afternoon
 * somebody tried eleven times to run a submission again. The refusal carries
 * the machine-readable reason the domain produced, and it is rendered as that
 * code rather than translated: the reasons come from a dozen different
 * services, they already appear verbatim in the application log, and an
 * operator comparing the two should be looking at the same string.
 *
 * The actor is whatever was recorded at the time — a name that has since
 * changed still reads as it was. Where there was nobody, the screen says which
 * kind of nobody: the platform itself, or somebody not signed in. "Not signed
 * in" beside a failed sign-in and an address is the most useful line here.
 */
const props = defineProps<{ page: AuditPage; filters: AuditFilters; options: AuditOptions }>();

const shared = usePage<SharedProps>();
const locale = shared.props.app.locale;

const action = ref(props.filters.action ?? '');
const subject = ref(props.filters.subject ?? '');
const outcome = ref(props.filters.outcome ?? '');
const subjectUuid = ref(props.filters.subject_uuid ?? '');

watch(
    () => props.filters,
    (filters) => {
        action.value = filters.action ?? '';
        subject.value = filters.subject ?? '';
        outcome.value = filters.outcome ?? '';
        subjectUuid.value = filters.subject_uuid ?? '';
    },
);

function currentQuery(): Record<string, string> {
    const query: Record<string, string> = {};

    if (action.value !== '') {
        query.action = action.value;
    }

    if (subject.value !== '') {
        query.subject = subject.value;
    }

    if (outcome.value !== '') {
        query.outcome = outcome.value;
    }

    if (subjectUuid.value !== '') {
        query.subject_uuid = subjectUuid.value;
    }

    return query;
}

function apply(): void {
    router.get('/system/audit', currentQuery(), { preserveState: true, preserveScroll: true, replace: true });
}

function cursorHref(cursor: string | null): string | null {
    return cursor === null ? null : `/system/audit?${new URLSearchParams({ ...currentQuery(), cursor }).toString()}`;
}

const actionOptions = computed(() => [
    { value: '', label: trans('ui.system.audit.all') },
    ...props.options.action.map((value) => ({ value, label: trans(`ui.system.audit.action.${value}`) })),
]);

const subjectOptions = computed(() => [
    { value: '', label: trans('ui.system.audit.all') },
    ...props.options.subject.map((value) => ({ value, label: trans(`ui.system.audit.subject.${value}`) })),
]);

const outcomeOptions = computed(() => [
    { value: '', label: trans('ui.system.audit.all') },
    ...props.options.outcome.map((value) => ({
        value,
        label: trans(`ui.system.audit.${value === 'SUCCEEDED' ? 'succeeded' : 'refused'}`),
    })),
]);

/**
 * Who, as it was recorded.
 *
 * Never falls back to a dash. "Nobody was signed in" and "we did not record
 * who" would look the same, and only one of them is ever true here.
 */
function actorOf(row: AuditRow): string {
    if (row.actor.kind === 'system') {
        return trans('ui.system.audit.actor_system');
    }

    if (row.actor.kind === 'guest' || row.actor.label === null) {
        return trans('ui.system.audit.actor_guest');
    }

    return row.actor.label;
}

/**
 * The scrubbed context, flattened for a cell.
 *
 * Rendered as `key: value` pairs rather than JSON: this column is read by a
 * person scanning for one fact, and a nested brace is noise. Values are
 * already bounded and redacted server-side.
 */
function detailsOf(row: AuditRow): string {
    if (row.context === null) {
        return '';
    }

    return Object.entries(row.context)
        .map(([key, value]) => `${key}: ${typeof value === 'object' ? JSON.stringify(value) : String(value)}`)
        .join(' · ');
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="text-page-title text-foreground">{{ trans('ui.system.audit.title') }}</h1>
            <p class="mt-1 text-small text-muted">{{ trans('ui.system.audit.description') }}</p>
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.catalog.filters') }}</template>
            <form class="grid gap-3 p-4 sm:grid-cols-4" @submit.prevent="apply">
                <FormField :label="trans('ui.system.audit.filter_action')">
                    <SelectInput v-model="action" :options="actionOptions" />
                </FormField>
                <FormField :label="trans('ui.system.audit.filter_subject')">
                    <SelectInput v-model="subject" :options="subjectOptions" />
                </FormField>
                <FormField :label="trans('ui.system.audit.filter_outcome')">
                    <SelectInput v-model="outcome" :options="outcomeOptions" />
                </FormField>
                <FormField :label="trans('ui.system.audit.filter_subject_uuid')">
                    <TextInput v-model="subjectUuid" />
                </FormField>
                <div class="sm:col-span-4">
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
                :title="trans('ui.system.audit.empty')"
                :description="trans('ui.system.audit.empty_note')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.system.audit.title')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.system.audit.when') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.system.audit.who') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.system.audit.what') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.system.audit.about') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.system.audit.origin') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.system.audit.details') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>{{ dateTime(row.occurred_at, locale) }}</TableCell>
                        <TableCell>
                            {{ actorOf(row) }}
                            <span v-if="row.actor.role !== null" class="ml-2 text-caption text-muted">
                                {{ row.actor.role }}
                            </span>
                        </TableCell>
                        <TableCell>
                            <span :class="row.significant ? 'font-medium text-foreground' : ''">
                                {{ trans(`ui.system.audit.action.${row.action}`) }}
                            </span>
                            <!--
                                A refusal is not an error to hide. The reason is
                                the code the domain produced, shown as-is so it
                                matches what the application log holds.
                            -->
                            <span v-if="row.outcome === 'REFUSED'" class="ml-2 text-caption text-danger">
                                {{ trans('ui.system.audit.refused') }}<template v-if="row.reason !== null">
                                    · {{ row.reason }}</template>
                            </span>
                        </TableCell>
                        <TableCell>
                            {{ trans(`ui.system.audit.subject.${row.subject}`) }}
                            <span v-if="row.subject_uuid !== null" class="ml-2 font-mono text-caption text-muted">
                                {{ row.subject_uuid.slice(0, 8) }}
                            </span>
                        </TableCell>
                        <TableCell>
                            <!-- Console work has no address, and saying so is
                                 different from having failed to record one. -->
                            <span v-if="row.ip_address === null" class="text-muted">
                                {{ trans('ui.system.audit.no_ip') }}
                            </span>
                            <span v-else class="font-mono text-caption">{{ row.ip_address }}</span>
                        </TableCell>
                        <TableCell>
                            <span class="text-caption text-muted">{{ detailsOf(row) }}</span>
                        </TableCell>
                    </tr>
                </DataTable>
                <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
            </template>
        </AppCard>

        <p class="text-caption text-muted">{{ trans('ui.system.audit.retention') }}</p>
    </div>
</template>
