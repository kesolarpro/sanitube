<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import CursorPagination from '@/Components/Data/CursorPagination.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import AppModal from '@/Components/Ui/AppModal.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { dateTime } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { ReleaseFilters, ReleasePage } from '@/Types/releases';

/**
 * The catalogue of releases.
 *
 * Ordered by when the record was created, not by release date, and the column
 * order says so: a back catalogue is entered in whatever order the boxes come
 * off the shelf, and a list that reshuffles as dates are filled in is one
 * nobody can work down.
 *
 * `release_date` is shown as "no date" rather than blank. A release with no
 * date is not ready, and an empty cell reads as a rendering fault.
 */
const props = defineProps<{ page: ReleasePage; filters: ReleaseFilters; options: Record<string, string[]> }>();

// Not `page`: the list payload is called `page` in the template, and a
// shadowed name there resolves to whichever one Vue reached first.
const shared = usePage<SharedProps>();
const locale = shared.props.app.locale;

// Presentation only. `can.role:catalogue` on the route is the authorisation,
// and hiding this button does not protect the endpoint — a test posts past it.
const mayCreate = computed(() => shared.props.auth.user?.can.catalogue === true);

const status = ref(props.filters.status ?? '');
const type = ref(props.filters.type ?? '');

watch(
    () => props.filters,
    (filters) => {
        status.value = filters.status ?? '';
        type.value = filters.type ?? '';
    },
);

function currentQuery(): Record<string, string> {
    const query: Record<string, string> = {};

    if (status.value !== '') {
        query.status = status.value;
    }

    if (type.value !== '') {
        query.type = type.value;
    }

    return query;
}

function apply(): void {
    router.get('/releases', currentQuery(), { preserveState: true, preserveScroll: true, replace: true });
}

function cursorHref(cursor: string | null): string | null {
    return cursor === null ? null : `/releases?${new URLSearchParams({ ...currentQuery(), cursor }).toString()}`;
}

const statusOptions = computed(() => [
    { value: '', label: trans('ui.releases.filter.any_status') },
    ...(props.options.status ?? []).map((value) => ({ value, label: trans(`ui.status.generic.${value}`) })),
]);

const typeOptions = computed(() => [
    { value: '', label: trans('ui.releases.filter.any_type') },
    ...(props.options.type ?? []).map((value) => ({ value, label: trans(`ui.releases.release_type.${value}`) })),
]);

const creatable = computed(() =>
    (props.options.type ?? []).map((value) => ({ value, label: trans(`ui.releases.release_type.${value}`) })),
);

const creating = ref(false);

const createForm = useForm({
    title: '',
    type: props.options.type?.[0] ?? 'SINGLE',
    version_title: '',
    language_code: '',
});

function openCreate(): void {
    createForm.clearErrors();
    createForm.title = '';
    createForm.version_title = '';
    createForm.language_code = '';
    creating.value = true;
}

function submitCreate(): void {
    // No redirect handling here: the controller sends the browser to the new
    // release, because a draft that is created and then left in a list is a
    // draft nobody finishes.
    createForm.post('/releases', {
        onSuccess: () => {
            creating.value = false;
        },
    });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-page-title text-foreground">{{ trans('ui.releases.title') }}</h1>
                <p class="mt-1 text-small text-muted">{{ trans('ui.releases.description') }}</p>
            </div>
            <AppButton v-if="mayCreate" variant="primary" @click="openCreate">
                {{ trans('ui.releases.new_release') }}
            </AppButton>
        </div>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.catalog.filters') }}</template>
            <form class="grid gap-3 p-4 sm:grid-cols-3" @submit.prevent="apply">
                <FormField :label="trans('ui.catalog.filter.status')">
                    <SelectInput v-model="status" :options="statusOptions" />
                </FormField>
                <FormField :label="trans('ui.releases.type')">
                    <SelectInput v-model="type" :options="typeOptions" />
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
                :title="trans('ui.releases.no_releases')"
                :description="trans('ui.releases.no_releases_description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.releases.title')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.releases.release_title') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.releases.type') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.releases.label_name') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.tracks') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.releases.release_date') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.ingestion.created_at') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/releases/${row.uuid}`" class="hover:text-accent">{{ row.title }}</Link>
                            <span v-if="row.version_title !== null" class="ml-2 text-caption text-muted">
                                {{ row.version_title }}
                            </span>
                        </TableCell>
                        <TableCell>{{ trans(`ui.releases.release_type.${row.type}`) }}</TableCell>
                        <TableCell><StatusBadge :status="row.status" group="generic" /></TableCell>
                        <TableCell>{{ row.label_name ?? '—' }}</TableCell>
                        <TableCell>{{ row.track_count }}</TableCell>
                        <TableCell>
                            <span v-if="row.release_date === null" class="text-muted">
                                {{ trans('ui.releases.no_date') }}
                            </span>
                            <span v-else>{{ row.release_date }}</span>
                        </TableCell>
                        <TableCell>{{ dateTime(row.created_at, locale) }}</TableCell>
                    </tr>
                </DataTable>
                <CursorPagination :prev="cursorHref(page.previous_cursor)" :next="cursorHref(page.next_cursor)" />
            </template>
        </AppCard>

        <AppModal
            :open="creating"
            :title="trans('ui.releases.new_release')"
            :description="trans('ui.releases.new_release_description')"
            @close="creating = false"
        >
            <div class="space-y-3">
                <FormField :label="trans('ui.releases.release_title')" :error="createForm.errors.title" required>
                    <TextInput v-model="createForm.title" />
                </FormField>
                <FormField :label="trans('ui.releases.type')" :error="createForm.errors.type" required>
                    <SelectInput v-model="createForm.type" :options="creatable" />
                </FormField>
                <FormField :label="trans('ui.releases.version_title')" :error="createForm.errors.version_title">
                    <TextInput v-model="createForm.version_title" />
                </FormField>
                <FormField :label="trans('ui.catalog.column.language')" :error="createForm.errors.language_code">
                    <TextInput v-model="createForm.language_code" />
                </FormField>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="creating = false">{{ trans('ui.actions.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="createForm.processing" @click="submitCreate">
                    {{ trans('ui.releases.create') }}
                </AppButton>
            </template>
        </AppModal>
    </div>
</template>
