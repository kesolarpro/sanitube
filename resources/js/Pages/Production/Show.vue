<script setup lang="ts">
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { dateTime } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import TextInput from '@/Components/Ui/TextInput.vue';
import TextareaInput from '@/Components/Ui/TextareaInput.vue';
import type { OccasionRow, ProductionPlanDetail, SelectableProfile } from '@/Types/production';

/**
 * One plan, and every occasion it opened.
 *
 * The screen that answers **"why did nothing happen on the 14th"**. Each
 * occasion carries the reason that settled it, said in words rather than as the
 * machine code the row holds — a list of `AUTONOMY_NOT_GRANTED` is a list
 * somebody has to look up.
 *
 * **A skip and a failure are shown differently.** An inventory that found
 * enough is the system working; rendering both as red would teach an operator
 * to stop reading the red ones.
 *
 * The buttons are presentation. `may_steer` hides them from a MEMBER, and the
 * route middleware is what actually refuses — a hidden button has never been an
 * authorisation.
 */
const props = defineProps<{
    plan: ProductionPlanDetail;
    occasions: OccasionRow[];
    may_steer: boolean;
    profiles: SelectableProfile[];
}>();

const page = usePage<SharedProps>();
const locale = page.props.app.locale;

const pausing = ref(false);
const cancelling = ref<string | null>(null);

const autonomy = useForm({ autonomy_mode: props.plan.autonomy_mode });

/**
 * PROD-002. Correcting the plan's terms.
 *
 * Deliberately not the status. Pausing, resuming and setting autonomy are named
 * acts with their own buttons above; a form that could assign a status would
 * put `EXHAUSTED` — a conclusion the platform draws from its own counting —
 * within reach of a select.
 *
 * The imprint is here because picking the wrong one at creation is an ordinary
 * mistake, and the alternative is a plan somebody has to abandon and rebuild.
 */
const editing = ref(false);

const terms = useForm({
    name: props.plan.name,
    editorial_profile: props.plan.editorial_profile_uuid ?? '',
    cadence_days: (props.plan.cadence_days ?? '') as string | number,
    target_track_count: (props.plan.target_track_count ?? '') as string | number,
    notes: props.plan.notes ?? '',
});

const profileOptions = computed(() =>
    props.profiles.map((profile) => ({ value: profile.uuid, label: profile.name })),
);

/** Blank means "no ceiling", and has to survive the trip through a text input. */
function optionalNumber(value: string | number): number | null {
    const text = String(value).trim();

    return text === '' ? null : Number(text);
}

function saveTerms(): void {
    terms
        .transform((data) => ({
            ...data,
            cadence_days: optionalNumber(data.cadence_days),
            target_track_count: optionalNumber(data.target_track_count),
        }))
        .patch(`/production/plans/${props.plan.uuid}`, {
            preserveScroll: true,
            onSuccess: () => {
                editing.value = false;
            },
        });
}

const actionError = computed<string | null>(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.production ?? null;
});

/**
 * The modes an operator may choose.
 *
 * The locked one is deliberately still listed. Hiding it would make the
 * platform look as though it could release on its own and simply had not been
 * asked to; showing it named as unavailable says what is actually true.
 */
const MODES = [
    'MANUAL',
    'ASSISTED',
    'AUTONOMOUS_GENERATION',
    'AUTONOMOUS_PREPARATION',
    'AUTONOMOUS_RELEASE',
];

function pause(): void {
    router.post(`/production/plans/${props.plan.uuid}/pause`, {}, {
        preserveScroll: true,
        onFinish: () => {
            pausing.value = false;
        },
    });
}

function resume(): void {
    router.post(`/production/plans/${props.plan.uuid}/resume`, {}, { preserveScroll: true });
}

function submitAutonomy(): void {
    autonomy.post(`/production/plans/${props.plan.uuid}/autonomy`, { preserveScroll: true });
}

function cancelOccasion(uuid: string): void {
    router.post(`/production/occasions/${uuid}/cancel`, {}, {
        preserveScroll: true,
        onFinish: () => {
            cancelling.value = null;
        },
    });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <Link href="/production" class="text-caption text-muted hover:text-accent">
                    {{ trans('ui.production.title') }}
                </Link>
                <h1 class="text-page-title text-foreground">{{ plan.name }}</h1>
                <p class="mt-1 text-small text-muted">
                    {{ trans(`ui.production.autonomy_mode.${plan.autonomy_mode}`) }}
                    <template v-if="plan.editorial_profile !== null">
                        · {{ trans('ui.production.profile') }}: {{ plan.editorial_profile }}
                    </template>
                    <template v-if="plan.cadence_days !== null">
                        · {{ trans('ui.production.cadence', { days: String(plan.cadence_days) }) }}
                    </template>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <StatusBadge :status="plan.status" group="production_plan" />
                <AppButton
                    v-if="may_steer && plan.is_runnable"
                    variant="danger"
                    size="sm"
                    @click="pausing = true"
                >
                    {{ trans('ui.production.pause') }}
                </AppButton>
                <AppButton
                    v-else-if="may_steer && plan.is_resumable"
                    variant="secondary"
                    size="sm"
                    @click="resume"
                >
                    {{ trans('ui.production.resume') }}
                </AppButton>
            </div>
        </div>

        <AppAlert v-if="actionError !== null" tone="danger" :title="trans('ui.ingestion.refused')">
            {{ trans(`ui.production.failure.${actionError}`) }}
        </AppAlert>

        <!--
            The platform stopping itself is not the same as somebody pausing it,
            and the reason is only ever set by the platform. Shown apart so an
            operator can tell which of their plans need them.
        -->
        <AppAlert
            v-if="plan.was_stopped_by_the_platform"
            tone="warning"
            :title="trans('ui.production.stopped_by_platform')"
        >
            <template v-if="plan.halted_reason !== null">
                {{ trans(`ui.production.reason.${plan.halted_reason}`) }}
            </template>
            <template v-if="plan.halted_at !== null"> · {{ dateTime(plan.halted_at, locale) }}</template>
        </AppAlert>

        <AppAlert
            v-if="plan.may_generate_unattended"
            tone="warning"
            :title="trans('ui.production.unattended_yes')"
        >
            {{ trans('ui.production.spends_money') }}
        </AppAlert>

        <AppCard v-if="may_steer" :title="trans('ui.production.edit_plan')">
            <template v-if="editing">
                <div class="grid gap-3 sm:grid-cols-2">
                    <FormField :label="trans('ui.production.plan')" :error="terms.errors.name">
                        <TextInput v-model="terms.name" autocomplete="off" />
                    </FormField>
                    <FormField
                        :label="trans('ui.production.editorial_profile')"
                        :error="terms.errors.editorial_profile"
                        :description="trans('ui.production.editorial_profile_hint')"
                    >
                        <SelectInput v-model="terms.editorial_profile" :options="profileOptions" />
                    </FormField>
                    <FormField
                        :label="trans('ui.production.cadence')"
                        :error="terms.errors.cadence_days"
                        :description="trans('ui.production.cadence_hint')"
                    >
                        <TextInput v-model="terms.cadence_days" inputmode="numeric" autocomplete="off" />
                    </FormField>
                    <FormField
                        :label="trans('ui.production.target')"
                        :error="terms.errors.target_track_count"
                        :description="trans('ui.production.target_hint')"
                    >
                        <TextInput v-model="terms.target_track_count" inputmode="numeric" autocomplete="off" />
                    </FormField>
                    <FormField :label="trans('ui.production.notes')" :error="terms.errors.notes">
                        <TextareaInput v-model="terms.notes" :rows="2" />
                    </FormField>
                </div>

                <!-- The slug is not on this form. It is frozen at creation
                     because it is how a console command names a plan, and one
                     that followed a rename would orphan every reference. -->
                <p class="mt-3 text-caption text-muted">{{ trans('ui.production.slug_frozen') }}</p>

                <div class="mt-4 flex justify-end gap-2">
                    <AppButton variant="secondary" @click="editing = false">
                        {{ trans('ui.actions.cancel') }}
                    </AppButton>
                    <AppButton variant="primary" :disabled="terms.processing" @click="saveTerms">
                        {{ trans('ui.actions.save') }}
                    </AppButton>
                </div>
            </template>
            <AppButton v-else variant="secondary" @click="editing = true">
                {{ trans('ui.production.edit_plan') }}
            </AppButton>
        </AppCard>

        <AppCard v-if="may_steer" :title="trans('ui.production.set_autonomy')">
            <form class="flex flex-wrap items-end gap-2" @submit.prevent="submitAutonomy">
                <FormField :label="trans('ui.production.autonomy')" :error="autonomy.errors.autonomy_mode">
                    <SelectInput
                        v-model="autonomy.autonomy_mode"
                        :options="MODES.map((mode) => ({ value: mode, label: trans(`ui.production.autonomy_mode.${mode}`) }))"
                    />
                </FormField>
                <AppButton type="submit" variant="primary" :disabled="autonomy.processing">
                    {{ trans('ui.production.set_autonomy_confirm') }}
                </AppButton>
            </form>
        </AppCard>

        <AppCard :padded="false" :title="trans('ui.production.occasions')">
            <EmptyState
                v-if="occasions.length === 0"
                :title="trans('ui.production.no_occasions')"
                :description="trans('ui.production.description')"
            />
            <DataTable v-else :caption="trans('ui.production.occasions')">
                <template #head>
                    <TableHeaderCell>{{ trans('ui.production.scheduled_for') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.production.outcome') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.production.outcome_reason') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.production.attempted_providers') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.production.generation') }}</TableHeaderCell>
                    <TableHeaderCell />
                </template>
                <tr v-for="occasion in occasions" :key="occasion.uuid" class="border-t border-border">
                    <TableCell>{{ occasion.scheduled_for }}</TableCell>
                    <TableCell><StatusBadge :status="occasion.status" group="occasion" /></TableCell>
                    <TableCell>
                        <!-- Said in words. A list of machine codes is a list
                             somebody has to look up. -->
                        <span v-if="occasion.outcome_reason === null" class="text-muted">—</span>
                        <span v-else class="text-small" :class="occasion.was_skipped ? 'text-muted' : ''">
                            {{ trans(`ui.production.reason.${occasion.outcome_reason}`) }}
                        </span>
                    </TableCell>
                    <TableCell>
                        <span v-if="occasion.attempted_providers.length === 0" class="text-muted">—</span>
                        <span v-else class="text-small">{{ occasion.attempted_providers.join(', ') }}</span>
                    </TableCell>
                    <TableCell>
                        <Link
                            v-if="occasion.generation !== null"
                            :href="`/studio/generations/${occasion.generation.uuid}`"
                            class="text-small hover:text-accent"
                        >
                            {{ trans(`ui.status.generic.${occasion.generation.status}`) }}
                        </Link>
                        <span v-else class="text-muted">—</span>
                    </TableCell>
                    <TableCell align="right">
                        <AppButton
                            v-if="may_steer && occasion.is_cancellable"
                            variant="ghost"
                            size="sm"
                            @click="cancelling = occasion.uuid"
                        >
                            {{ trans('ui.production.cancel_occasion') }}
                        </AppButton>
                    </TableCell>
                </tr>
            </DataTable>
        </AppCard>

        <!-- Pausing stops new occasions and stops workers acting on existing
             ones. It recalls nothing already requested at a supplier, and the
             dialog says so rather than letting somebody assume otherwise. -->
        <ConfirmDialog
            :open="pausing"
            :title="trans('ui.production.pause')"
            :description="trans('ui.production.pause_description')"
            :confirm-label="trans('ui.production.pause_confirm')"
            destructive
            @confirm="pause"
            @cancel="pausing = false"
        />

        <ConfirmDialog
            :open="cancelling !== null"
            :title="trans('ui.production.cancel_occasion')"
            :description="trans('ui.production.cancel_occasion_description')"
            :confirm-label="trans('ui.production.cancel_occasion_confirm')"
            destructive
            @confirm="cancelling !== null && cancelOccasion(cancelling)"
            @cancel="cancelling = null"
        />
    </div>
</template>
