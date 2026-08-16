<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import DataTable from '@/Components/Data/DataTable.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import AppModal from '@/Components/Ui/AppModal.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import TextareaInput from '@/Components/Ui/TextareaInput.vue';
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { dateTime } from '@/Support/format';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';
import type { DeliveryDetail } from '@/Types/distribution';

/**
 * One handover, and every conversation it has had.
 *
 * The screen is built around three states that must never be drawn the same
 * way, because collapsing them is how a local record starts disagreeing with
 * reality:
 *
 *   - **Never checked** — nobody has asked the distributor. Not "nothing has
 *     changed": the distributor may have accepted this weeks ago.
 *   - **Failed** — SaniTube could not reach the distributor. Retryable, and
 *     not a verdict anybody gave.
 *   - **Rejected** — the distributor looked and said no. A verdict.
 *
 * Nothing on this page asks the distributor anything. Checking is a button.
 */
const props = defineProps<{ delivery: DeliveryDetail }>();

const page = usePage<SharedProps>();
const locale = page.props.app.locale;

const refusal = computed(() => {
    const errors = page.props.errors as Record<string, string> | undefined;

    return errors?.distribution ?? null;
});

const syncing = ref(false);
const takingDown = ref(false);
const acting = ref(false);

function sync(): void {
    syncing.value = true;

    router.post(
        `/distribution/${props.delivery.uuid}/sync`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                syncing.value = false;
            },
        },
    );
}

function requestTakedown(): void {
    acting.value = true;

    router.post(
        `/distribution/${props.delivery.uuid}/takedown`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                acting.value = false;
                takingDown.value = false;
            },
        },
    );
}

const isFailed = computed(() => props.delivery.status === 'FAILED');
const isRejected = computed(() => props.delivery.status === 'REJECTED');
const isUnconfirmed = computed(() => props.delivery.status === 'SUBMITTED_UNCONFIRMED');

const reconciling = ref(false);
const resolving = ref(false);

const resolveForm = useForm({ arrived: true, external_release_id: '', note: '' });

function reconcile(): void {
    reconciling.value = true;

    router.post(
        `/distribution/${props.delivery.uuid}/reconcile`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                reconciling.value = false;
            },
        },
    );
}

function openResolve(): void {
    resolveForm.clearErrors();
    resolveForm.arrived = true;
    resolveForm.external_release_id = '';
    resolveForm.note = '';
    resolving.value = true;
}

/**
 * A select rather than a toggle. "Arrived / never arrived" is a statement about
 * the world, and a switch labelled with one of the two answers reads as a
 * setting somebody left on.
 */
const arrivedChoice = computed({
    get: () => (resolveForm.arrived ? 'yes' : 'no'),
    set: (value: string) => {
        resolveForm.arrived = value === 'yes';
    },
});

function submitResolve(): void {
    resolveForm.post(`/distribution/${props.delivery.uuid}/resolve`, {
        preserveScroll: true,
        onSuccess: () => {
            resolving.value = false;
        },
    });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-caption text-muted">
                    <Link href="/distribution" class="hover:text-accent">{{ trans('ui.distribution.title') }}</Link>
                </p>
                <h1 class="text-page-title text-foreground">{{ delivery.release?.title ?? delivery.provider }}</h1>
                <p class="mt-1 text-small text-muted">{{ delivery.provider }}</p>
            </div>
            <StatusBadge :status="delivery.status" group="generic" />
        </div>

        <AppAlert v-if="refusal !== null" tone="danger" :title="trans('ui.ingestion.refused')">
            {{ trans(`ui.distribution.failure.${refusal}`) }}
        </AppAlert>

        <!-- The distributor, as configured. Not probed on render. -->
        <AppAlert
            v-if="!delivery.distributor.known"
            tone="warning"
            :title="trans('ui.distribution.unknown_provider')"
        >
            {{ trans('ui.distribution.unknown_provider_note') }}
        </AppAlert>

        <AppAlert
            v-else-if="!delivery.distributor.available"
            tone="warning"
            :title="trans('ui.distribution.unavailable')"
        >
            {{ trans('ui.distribution.unavailable_note') }}
        </AppAlert>

        <AppAlert
            v-if="delivery.distributor.sandbox === true"
            tone="info"
            :title="trans('ui.distribution.sandbox')"
        >
            {{ trans('ui.distribution.sandbox_note') }}
        </AppAlert>

        <!-- Failed and rejected are opposite answers and are never drawn the
             same way. One is "nobody could ask"; the other is "they said no". -->
        <AppAlert v-if="isFailed" tone="warning" :title="trans('ui.status.generic.FAILED')">
            {{ trans('ui.distribution.failed_is_not_rejected') }}
        </AppAlert>

        <AppAlert v-else-if="isRejected" tone="danger" :title="trans('ui.status.generic.REJECTED')">
            {{ trans('ui.distribution.rejected_is_a_verdict') }}
        </AppAlert>

        <!-- DIST-001-H1. Neither a failure nor a success, and the only state
             with no retry: the distributor may already hold the package. -->
        <AppAlert v-else-if="isUnconfirmed" tone="warning" :title="trans('ui.distribution.unconfirmed')">
            {{ trans('ui.distribution.unconfirmed_note') }}
        </AppAlert>

        <AppCard>
            <dl class="grid gap-3 sm:grid-cols-3">
                <div v-if="delivery.release !== null">
                    <dt class="text-caption text-muted">{{ trans('ui.catalog.column.releases') }}</dt>
                    <dd class="text-small">
                        <Link :href="`/releases/${delivery.release.uuid}`" class="hover:text-accent">
                            {{ delivery.release.title }}
                        </Link>
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.distribution.provider') }}</dt>
                    <dd class="text-small text-foreground">{{ delivery.provider }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.distribution.submitted_at') }}</dt>
                    <dd class="text-small text-foreground">
                        <span v-if="delivery.submitted_at === null" class="text-muted">
                            {{ trans('ui.distribution.not_reached_them') }}
                        </span>
                        <span v-else>{{ dateTime(delivery.submitted_at, locale) }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.distribution.delivered_at') }}</dt>
                    <dd class="text-small text-foreground">{{ dateTime(delivery.delivered_at, locale) }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.distribution.live_at') }}</dt>
                    <dd class="text-small text-foreground">{{ dateTime(delivery.live_at, locale) }}</dd>
                </div>
                <div>
                    <dt class="text-caption text-muted">{{ trans('ui.distribution.last_synced_at') }}</dt>
                    <dd class="text-small">
                        <span v-if="delivery.last_synced_at === null" class="text-warning">
                            {{ trans('ui.distribution.never_synced') }}
                        </span>
                        <span v-else class="text-foreground">{{ dateTime(delivery.last_synced_at, locale) }}</span>
                    </dd>
                </div>
            </dl>

            <p v-if="delivery.last_synced_at === null" class="mt-3 text-caption text-muted">
                {{ trans('ui.distribution.never_synced_note') }}
            </p>

            <p class="mt-3 text-small text-muted">
                {{
                    delivery.has_external_reference
                        ? trans('ui.distribution.reached_them')
                        : trans('ui.distribution.not_reached_them')
                }}
            </p>

            <div v-if="delivery.failure_reason !== null" class="mt-4 border-t border-border pt-3">
                <p class="text-caption text-muted">{{ trans('ui.distribution.failure_reason') }}</p>
                <p class="mt-1 text-small text-foreground">{{ delivery.failure_reason }}</p>
            </div>
        </AppCard>

        <AppCard v-if="delivery.actions.may_distribute">
            <template #header>{{ trans('ui.actions.confirm') }}</template>

            <div class="flex flex-wrap gap-2">
                <AppButton
                    v-if="delivery.actions.can_sync"
                    variant="secondary"
                    :loading="syncing"
                    @click="sync"
                >
                    {{ trans('ui.distribution.check_now') }}
                </AppButton>

                <AppButton
                    v-if="delivery.actions.can_reconcile"
                    variant="primary"
                    :loading="reconciling"
                    @click="reconcile"
                >
                    {{ trans('ui.distribution.reconcile') }}
                </AppButton>

                <AppButton
                    v-if="delivery.actions.can_resolve_manually"
                    variant="secondary"
                    @click="openResolve"
                >
                    {{ trans('ui.distribution.resolve') }}
                </AppButton>

                <AppButton
                    v-if="delivery.actions.can_request_takedown"
                    variant="danger"
                    @click="takingDown = true"
                >
                    {{ trans('ui.distribution.takedown') }}
                </AppButton>
            </div>

            <p v-if="delivery.actions.can_reconcile" class="mt-3 text-caption text-muted">
                {{ trans('ui.distribution.reconcile_description') }}
            </p>

            <!-- The distributor cannot be asked, so a person has to look. Said
                 rather than left as a missing button. -->
            <p
                v-else-if="delivery.actions.can_resolve_manually"
                class="mt-3 text-caption text-muted"
            >
                {{ trans('ui.distribution.reconcile_unavailable') }}
            </p>
        </AppCard>

        <AppCard :padded="false">
            <template #header>{{ trans('ui.distribution.attempt_log') }}</template>

            <p v-if="delivery.attempts.length === 0" class="p-4 text-small text-muted">
                {{ trans('ui.distribution.no_attempts') }}
            </p>

            <DataTable v-else :caption="trans('ui.distribution.attempt_log')">
                <template #head>
                    <TableHeaderCell>{{ trans('ui.ingestion.created_at') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.catalog.column.actions') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.catalog.column.status') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.catalog.column.duration') }}</TableHeaderCell>
                    <TableHeaderCell>{{ trans('ui.distribution.attempt_log') }}</TableHeaderCell>
                </template>
                <tr v-for="attempt in delivery.attempts" :key="attempt.uuid" class="border-t border-border">
                    <TableCell>{{ dateTime(attempt.created_at, locale) }}</TableCell>
                    <TableCell>{{ trans(`ui.distribution.action.${attempt.action}`) }}</TableCell>
                    <TableCell>{{ trans(`ui.distribution.outcome.${attempt.outcome}`) }}</TableCell>
                    <TableCell>{{ attempt.duration_ms === null ? '—' : `${attempt.duration_ms} ms` }}</TableCell>
                    <TableCell>{{ attempt.summary ?? '—' }}</TableCell>
                </tr>
            </DataTable>
        </AppCard>

        <!-- The one place SaniTube writes a reference it never received. -->
        <AppModal
            :open="resolving"
            :title="trans('ui.distribution.resolve')"
            :description="trans('ui.distribution.resolve_description')"
            @close="resolving = false"
        >
            <div class="space-y-3">
                <SelectInput
                    v-model="arrivedChoice"
                    :options="[
                        { value: 'yes', label: trans('ui.distribution.resolve_arrived') },
                        { value: 'no', label: trans('ui.distribution.resolve_not_arrived') },
                    ]"
                />

                <FormField
                    v-if="resolveForm.arrived"
                    :label="trans('ui.distribution.resolve_reference')"
                    :hint="trans('ui.distribution.resolve_reference_hint')"
                    :error="resolveForm.errors.external_release_id"
                    required
                >
                    <TextInput v-model="resolveForm.external_release_id" />
                </FormField>

                <FormField
                    :label="trans('ui.distribution.resolve_note')"
                    :error="resolveForm.errors.note"
                    required
                >
                    <TextareaInput v-model="resolveForm.note" :rows="3" />
                </FormField>
            </div>

            <template #footer>
                <AppButton variant="ghost" @click="resolving = false">{{ trans('ui.actions.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="resolveForm.processing" @click="submitResolve">
                    {{ trans('ui.distribution.resolve_confirm') }}
                </AppButton>
            </template>
        </AppModal>

        <ConfirmDialog
            :open="takingDown"
            :title="trans('ui.distribution.takedown')"
            :description="trans('ui.distribution.takedown_description')"
            :confirm-label="trans('ui.distribution.takedown_confirm')"
            destructive
            :busy="acting"
            @cancel="takingDown = false"
            @confirm="requestTakedown"
        />
    </div>
</template>
