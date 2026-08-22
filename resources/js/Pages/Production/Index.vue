<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import CursorPagination from '@/Components/Data/CursorPagination.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import TextareaInput from '@/Components/Ui/TextareaInput.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { ProductionOptions, ProductionPlanPage, SelectableProfile } from '@/Types/production';

/**
 * What the platform has been told to do on its own.
 *
 * **Autonomy and running are shown separately, and that is the point of the
 * table.** A plan can be granted the right to act alone and be paused, and an
 * operator looking at a quiet month needs to know which of the two it is —
 * folding them into one column would make "why has nothing happened" a question
 * only a database answers.
 *
 * Skipped and failed are separate columns for the same reason. An inventory
 * that found enough is the system working; a column that summed them would
 * teach somebody to ignore the failure count.
 */
const props = defineProps<{
    page: ProductionPlanPage;
    options: ProductionOptions;
    profiles: SelectableProfile[];
}>();

// Named `inertia` rather than `page`: this screen's own prop is called
// `page`, and shadowing it here is how a template silently reads the wrong
// object.
const inertia = usePage<SharedProps>();

/**
 * PROD-002. Until this form, nothing in the product could make a plan.
 *
 * `WriteProductionPlan::create` shipped with PROD-001 and had no caller: no
 * controller, no console command, no seeder. Neither did the editorial profile
 * a plan requires. So this screen could only ever be empty, and the one part of
 * SaniTube that acts unattended could not be started from inside it.
 */
const mayWrite = computed(() => inertia.props.auth.user?.can.catalogue === true);

const refusal = computed(() => {
    const errors = inertia.props.errors as Record<string, string> | undefined;

    return errors?.production ?? null;
});

const adding = ref(false);

const form = useForm({
    name: '',
    editorial_profile: '',
    autonomy_mode: 'MANUAL',
    cadence_days: '' as string | number,
    target_track_count: '' as string | number,
    notes: '',
});

const profileOptions = computed(() =>
    props.profiles.map((profile) => ({ value: profile.uuid, label: profile.name })),
);

const autonomyOptions = computed(() =>
    props.options.autonomy.map((mode) => ({ value: mode, label: trans(`ui.production.autonomy_mode.${mode}`) })),
);

/**
 * Blank means "no ceiling", and has to survive the trip through a text input.
 *
 * An empty field posted as `''` fails an integer rule, and a plan with no
 * target is a legitimate standing intention — the shape of an open-ended
 * imprint. Sent as null, it reaches the writer as the absence it is.
 */
function optionalNumber(value: string | number): number | null {
    const text = String(value).trim();

    return text === '' ? null : Number(text);
}

function create(): void {
    form
        .transform((data) => ({
            ...data,
            cadence_days: optionalNumber(data.cadence_days),
            target_track_count: optionalNumber(data.target_track_count),
        }))
        .post('/production/plans', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                adding.value = false;
            },
        });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-page-title text-foreground">{{ trans('ui.production.title') }}</h1>
                <p class="mt-1 text-small text-muted">{{ trans('ui.production.description') }}</p>
            </div>
            <AppButton v-if="mayWrite" variant="primary" @click="adding = !adding">
                {{ trans('ui.production.add_plan') }}
            </AppButton>
        </div>

        <AppAlert v-if="refusal !== null" tone="danger" :title="trans('ui.states.error_title')">
            {{ trans(`ui.production.failure.${refusal}`) }}
        </AppAlert>

        <!--
            An installation with no imprint cannot have a plan, and the form
            would be one whose only required field has nothing in it. Said
            plainly, with the way out, rather than shown as an empty select.
        -->
        <AppAlert
            v-if="mayWrite && profiles.length === 0"
            tone="info"
            :title="trans('ui.production.no_profiles')"
        >
            {{ trans('ui.production.no_profiles_description') }}
            <Link href="/editorial" class="underline hover:text-accent">
                {{ trans('ui.navigation.editorial') }}
            </Link>
        </AppAlert>

        <AppCard v-if="adding && profiles.length > 0">
            <template #header>{{ trans('ui.production.add_plan') }}</template>

            <div class="grid gap-3 sm:grid-cols-2">
                <FormField :label="trans('ui.production.plan')" :error="form.errors.name" required>
                    <TextInput v-model="form.name" autocomplete="off" />
                </FormField>
                <FormField
                    :label="trans('ui.production.editorial_profile')"
                    :error="form.errors.editorial_profile"
                    :description="trans('ui.production.editorial_profile_hint')"
                    required
                >
                    <SelectInput v-model="form.editorial_profile" :options="profileOptions" />
                </FormField>
                <FormField
                    :label="trans('ui.production.autonomy')"
                    :error="form.errors.autonomy_mode"
                    :description="trans('ui.production.autonomy_hint')"
                >
                    <SelectInput v-model="form.autonomy_mode" :options="autonomyOptions" />
                </FormField>
                <FormField
                    :label="trans('ui.production.cadence')"
                    :error="form.errors.cadence_days"
                    :description="trans('ui.production.cadence_hint')"
                >
                    <TextInput v-model="form.cadence_days" inputmode="numeric" autocomplete="off" />
                </FormField>
                <FormField
                    :label="trans('ui.production.target')"
                    :error="form.errors.target_track_count"
                    :description="trans('ui.production.target_hint')"
                >
                    <TextInput v-model="form.target_track_count" inputmode="numeric" autocomplete="off" />
                </FormField>
                <FormField :label="trans('ui.production.notes')" :error="form.errors.notes">
                    <TextareaInput v-model="form.notes" :rows="2" />
                </FormField>
            </div>

            <!-- The service's decision, repeated here because it is the
                 surprising one: a plan starts ACTIVE. One that arrived paused
                 is a plan somebody has to remember to start, which is how a
                 body of work quietly never happens. -->
            <p class="mt-3 text-caption text-muted">{{ trans('ui.production.starts_active') }}</p>

            <div class="mt-4 flex justify-end gap-2">
                <AppButton variant="secondary" @click="adding = false">
                    {{ trans('ui.actions.cancel') }}
                </AppButton>
                <AppButton variant="primary" :disabled="form.processing" @click="create">
                    {{ trans('ui.actions.save') }}
                </AppButton>
            </div>
        </AppCard>

        <!--
            Said on the screen rather than in a report nobody opens. A plan that
            may act alone turns a cadence into requests paid for at a supplier,
            and that is the one fact somebody should not have to infer.
        -->
        <AppAlert
            v-if="page.rows.some((row) => row.may_generate_unattended)"
            tone="warning"
            :title="trans('ui.production.title')"
        >
            {{ trans('ui.production.spends_money') }}
        </AppAlert>

        <AppCard :padded="false">
            <EmptyState
                v-if="page.rows.length === 0"
                :title="trans('ui.production.no_plans')"
                :description="trans('ui.production.description')"
            />
            <template v-else>
                <DataTable :caption="trans('ui.production.plans')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.production.plan') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.production.autonomy') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.production.occasions') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.status.occasion.SKIPPED') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.status.occasion.FAILED') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.production.target') }}</TableHeaderCell>
                    </template>
                    <tr v-for="row in page.rows" :key="row.uuid" class="border-t border-border">
                        <TableCell>
                            <Link :href="`/production/plans/${row.uuid}`" class="hover:text-accent">
                                {{ row.name }}
                            </Link>
                            <span v-if="row.editorial_profile !== null" class="block text-caption text-muted">
                                {{ row.editorial_profile }}
                            </span>
                        </TableCell>
                        <TableCell><StatusBadge :status="row.status" group="production_plan" /></TableCell>
                        <TableCell>
                            <span class="text-small">
                                {{ trans(`ui.production.autonomy_mode.${row.autonomy_mode}`) }}
                            </span>
                            <span
                                class="block text-caption"
                                :class="row.may_generate_unattended ? 'text-warning' : 'text-muted'"
                            >
                                {{
                                    row.may_generate_unattended
                                        ? trans('ui.production.unattended_yes')
                                        : trans('ui.production.unattended_no')
                                }}
                            </span>
                        </TableCell>
                        <TableCell align="right" numeric>{{ row.occasions.total }}</TableCell>
                        <TableCell align="right" numeric>{{ row.occasions.SKIPPED }}</TableCell>
                        <TableCell align="right" numeric>{{ row.occasions.FAILED }}</TableCell>
                        <TableCell align="right" numeric>
                            <span v-if="row.target_track_count === null" class="text-muted">
                                {{ trans('ui.production.no_target') }}
                            </span>
                            <span v-else>{{ row.target_track_count }}</span>
                        </TableCell>
                    </tr>
                </DataTable>
                <CursorPagination
                    :prev="page.previous_cursor === null ? null : `/production?cursor=${encodeURIComponent(page.previous_cursor)}`"
                    :next="page.next_cursor === null ? null : `/production?cursor=${encodeURIComponent(page.next_cursor)}`"
                />
            </template>
        </AppCard>

        <!-- The vocabulary the server owns, so a filter added later has it. -->
        <p class="sr-only">{{ options.status.join(' ') }}</p>
    </div>
</template>
