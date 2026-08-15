<script setup lang="ts">
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppButton from '@/Components/Ui/AppButton.vue';
import AppCard from '@/Components/Ui/AppCard.vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppModal from '@/Components/Ui/AppModal.vue';
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue';
import AppSkeleton from '@/Components/Ui/AppSkeleton.vue';
import ProgressBar from '@/Components/Ui/ProgressBar.vue';
import MetricCard from '@/Components/Ui/MetricCard.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import ErrorState from '@/Components/Ui/ErrorState.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import FormField from '@/Components/Ui/FormField.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import TextareaInput from '@/Components/Ui/TextareaInput.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import ToggleInput from '@/Components/Ui/ToggleInput.vue';
import DataTable from '@/Components/Data/DataTable.vue';
import TableHeaderCell from '@/Components/Data/TableHeaderCell.vue';
import TableCell from '@/Components/Data/TableCell.vue';
import { trans } from '@/Support/i18n';
import { duration } from '@/Support/format';

/**
 * The design system, shown to itself.
 *
 * **No domain data.** Nothing on this page queries the catalogue, and the
 * sample values below are deliberately obvious placeholders rather than
 * plausible figures — a showcase displaying "1,284 tracks" would be exactly
 * the invented metric the platform forbids. The metric cards therefore show
 * `null`, which is what the components render as an em dash and is the honest
 * state for a screen with nothing behind it.
 */
const modalOpen = ref(false);
const confirmOpen = ref(false);
const text = ref('');
const notes = ref('');
const choice = ref<string | null>(null);
const enabled = ref(true);
</script>

<template>
    <Head :title="trans('ui.design_system.title')" />

    <div class="space-y-8">
        <header>
            <h1 class="text-page-title text-foreground">{{ trans('ui.design_system.title') }}</h1>
            <p class="mt-1 max-w-2xl text-small text-muted">{{ trans('ui.design_system.description') }}</p>
        </header>

        <AppAlert tone="info" :title="trans('ui.design_system.no_data_title')">
            {{ trans('ui.design_system.no_data_body') }}
        </AppAlert>

        <!-- Metrics: null on purpose. See the script comment. -->
        <section class="space-y-3">
            <h2 class="text-section-title text-foreground">{{ trans('ui.design_system.metrics') }}</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard :label="trans('ui.design_system.sample_metric')" :value="null" :hint="trans('ui.design_system.no_value')" />
                <MetricCard :label="trans('ui.design_system.sample_metric')" :value="null" :hint="trans('ui.design_system.no_value')" />
                <MetricCard :label="trans('ui.design_system.sample_metric')" :value="null" :hint="trans('ui.design_system.no_value')" />
                <MetricCard :label="trans('ui.design_system.sample_metric')" :value="null" :hint="trans('ui.design_system.no_value')" />
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-section-title text-foreground">{{ trans('ui.design_system.buttons') }}</h2>
            <AppCard>
                <div class="flex flex-wrap items-center gap-2">
                    <AppButton variant="primary">{{ trans('ui.actions.save') }}</AppButton>
                    <AppButton variant="secondary">{{ trans('ui.actions.cancel') }}</AppButton>
                    <AppButton variant="ghost">{{ trans('ui.actions.close') }}</AppButton>
                    <AppButton variant="danger">{{ trans('ui.actions.delete') }}</AppButton>
                    <AppButton variant="primary" loading>{{ trans('ui.states.loading') }}</AppButton>
                    <AppButton variant="secondary" disabled>{{ trans('ui.states.disabled') }}</AppButton>
                    <AppButton size="sm" variant="secondary">{{ trans('ui.design_system.small') }}</AppButton>
                </div>
            </AppCard>
        </section>

        <section class="space-y-3">
            <h2 class="text-section-title text-foreground">{{ trans('ui.design_system.statuses') }}</h2>
            <AppCard>
                <div class="flex flex-wrap gap-2">
                    <StatusBadge status="DRAFT" group="generic" />
                    <StatusBadge status="PENDING" group="generic" />
                    <StatusBadge status="PROCESSING" group="generic" />
                    <StatusBadge status="READY" group="generic" />
                    <StatusBadge status="NEEDS_REVIEW" group="generic" />
                    <StatusBadge status="WAITING_CAPABILITY" group="generic" />
                    <StatusBadge status="DUPLICATE" group="generic" />
                    <StatusBadge status="FAILED" group="generic" />
                    <StatusBadge status="LIVE" group="generic" />
                </div>
            </AppCard>
        </section>

        <section class="space-y-3">
            <h2 class="text-section-title text-foreground">{{ trans('ui.design_system.forms') }}</h2>
            <AppCard>
                <div class="grid max-w-xl gap-4">
                    <FormField v-slot="field" :label="trans('ui.design_system.field_label')" :hint="trans('ui.design_system.field_hint')">
                        <TextInput v-model="text" v-bind="field" :placeholder="trans('ui.design_system.field_placeholder')" />
                    </FormField>

                    <FormField v-slot="field" :label="trans('ui.design_system.field_invalid')" :error="trans('ui.design_system.field_error')">
                        <TextInput v-model="text" v-bind="field" />
                    </FormField>

                    <FormField v-slot="field" :label="trans('ui.design_system.field_select')">
                        <SelectInput
                            v-model="choice"
                            v-bind="field"
                            :placeholder="trans('ui.design_system.field_placeholder')"
                            :options="[
                                { value: 'a', label: trans('ui.design_system.option_a') },
                                { value: 'b', label: trans('ui.design_system.option_b') },
                            ]"
                        />
                    </FormField>

                    <FormField v-slot="field" :label="trans('ui.design_system.field_notes')">
                        <TextareaInput v-model="notes" v-bind="field" />
                    </FormField>

                    <div class="flex items-center gap-2.5">
                        <ToggleInput v-model="enabled" :label="trans('ui.design_system.field_toggle')" />
                        <span class="text-small text-foreground">{{ trans('ui.design_system.field_toggle') }}</span>
                    </div>
                </div>
            </AppCard>
        </section>

        <section class="space-y-3">
            <h2 class="text-section-title text-foreground">{{ trans('ui.design_system.table') }}</h2>
            <AppCard :padded="false">
                <DataTable :caption="trans('ui.design_system.table_caption')">
                    <template #head>
                        <TableHeaderCell>{{ trans('ui.design_system.column_name') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.design_system.column_identifier') }}</TableHeaderCell>
                        <TableHeaderCell>{{ trans('ui.design_system.column_status') }}</TableHeaderCell>
                        <TableHeaderCell align="right">{{ trans('ui.design_system.column_duration') }}</TableHeaderCell>
                    </template>

                    <tr>
                        <TableCell>{{ trans('ui.design_system.row_placeholder') }}</TableCell>
                        <TableCell><CodeValue value="AA-AAA-00-00000" /></TableCell>
                        <TableCell><StatusBadge status="READY" group="generic" /></TableCell>
                        <TableCell align="right" numeric>{{ duration(213546) }}</TableCell>
                    </tr>
                    <tr>
                        <TableCell>{{ trans('ui.design_system.row_placeholder') }}</TableCell>
                        <TableCell><CodeValue value="AA-AAA-00-00001" /></TableCell>
                        <TableCell><StatusBadge status="NEEDS_REVIEW" group="generic" /></TableCell>
                        <TableCell align="right" numeric>{{ duration(null) }}</TableCell>
                    </tr>
                </DataTable>
            </AppCard>
        </section>

        <section class="grid gap-3 lg:grid-cols-2">
            <AppCard>
                <template #header>{{ trans('ui.design_system.empty') }}</template>
                <EmptyState :title="trans('ui.states.empty_title')" :description="trans('ui.states.empty_description')" />
            </AppCard>
            <AppCard>
                <template #header>{{ trans('ui.design_system.error') }}</template>
                <ErrorState :title="trans('ui.states.error_title')" :description="trans('ui.states.error_description')" />
            </AppCard>
        </section>

        <section class="grid gap-3 lg:grid-cols-2">
            <AppCard>
                <template #header>{{ trans('ui.design_system.loading') }}</template>
                <AppSkeleton :lines="4" />
            </AppCard>
            <AppCard>
                <template #header>{{ trans('ui.design_system.progress') }}</template>
                <div class="space-y-3">
                    <ProgressBar :value="3" :max="10" :label="trans('ui.design_system.progress')" />
                    <ProgressBar :value="7" :max="10" :label="trans('ui.design_system.progress')" />
                </div>
            </AppCard>
        </section>

        <section class="space-y-3">
            <h2 class="text-section-title text-foreground">{{ trans('ui.design_system.overlays') }}</h2>
            <AppCard>
                <div class="flex flex-wrap gap-2">
                    <AppButton variant="secondary" @click="modalOpen = true">{{ trans('ui.design_system.open_modal') }}</AppButton>
                    <AppButton variant="danger" @click="confirmOpen = true">{{ trans('ui.design_system.open_confirm') }}</AppButton>
                </div>
            </AppCard>
        </section>

        <AppModal
            :open="modalOpen"
            :title="trans('ui.design_system.modal_title')"
            :description="trans('ui.design_system.modal_description')"
            @close="modalOpen = false"
        >
            <p class="text-small text-muted">{{ trans('ui.design_system.modal_body') }}</p>
            <template #footer>
                <AppButton variant="ghost" @click="modalOpen = false">{{ trans('ui.actions.close') }}</AppButton>
            </template>
        </AppModal>

        <ConfirmDialog
            :open="confirmOpen"
            destructive
            :title="trans('ui.design_system.confirm_title')"
            :description="trans('ui.design_system.confirm_description')"
            :confirm-label="trans('ui.actions.delete')"
            @cancel="confirmOpen = false"
            @confirm="confirmOpen = false"
        />
    </div>
</template>
