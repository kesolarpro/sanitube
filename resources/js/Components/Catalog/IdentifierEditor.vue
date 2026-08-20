<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import AppButton from '@/Components/Ui/AppButton.vue';
import CodeValue from '@/Components/Ui/CodeValue.vue';
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import TextareaInput from '@/Components/Ui/TextareaInput.vue';
import { trans } from '@/Support/i18n';
import type { CatalogIdentifier } from '@/Types/catalog';

/**
 * Identifiers, on whatever carries them.
 *
 * CAT-005. One component for both the release builder and the track screen,
 * because the rules are identical and two copies would drift on the one thing
 * that must not drift: a revocation that fails to ask for a reason, or a form
 * that quietly offers a type the server refuses.
 *
 * **The platform never invents an identifier.** Every value here was typed in
 * by somebody holding it. A recording distributed elsewhere already has an
 * ISRC, and a second one minted here would split its earnings across two
 * numbers — which surfaces months later in a royalty report that no longer
 * reconciles. That is why there is no "generate" button and never will be.
 *
 * **Revoking is not deleting.** The row stays for ever: an identifier that
 * reached a distributor exists in reports and store metadata SaniTube does not
 * control. What changes is that it stops being current, and the reason is
 * required — "revoked, no reason given" is a row nobody can act on years later,
 * which is exactly when it will be read.
 *
 * The type list comes from the server. The browser does not decide what may be
 * assigned, and the controller asks the same question again: distributor and
 * DSP identifiers are absent from both, because a person typing one would be
 * recording a delivery reference for a delivery that never happened.
 */
const props = defineProps<{
    identifiers: CatalogIdentifier[];
    assignableTypes: string[];
    /** Where this entity's identifiers live, e.g. `/releases/<uuid>`. */
    endpoint: string;
    canAssign: boolean;
    /** A refusal code from the last attempt, or null. */
    refusal: string | null;
}>();

const typeOptions = computed(() =>
    props.assignableTypes.map((value) => ({ value, label: trans(`ui.catalog.identifier_type.${value}`) })),
);

const assignForm = useForm({
    type: props.assignableTypes[0] ?? '',
    value: '',
});

function assign(): void {
    assignForm.post(`${props.endpoint}/identifiers`, {
        preserveScroll: true,
        onSuccess: () => assignForm.reset('value'),
    });
}

/* ------------------------------------------------------------- revocation */

const revoking = ref<CatalogIdentifier | null>(null);
const reason = ref('');
const busy = ref(false);

function confirmRevoke(): void {
    const target = revoking.value;

    if (target === null) {
        return;
    }

    busy.value = true;

    router.delete(`${props.endpoint}/identifiers/${target.uuid}`, {
        data: { reason: reason.value },
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            revoking.value = null;
            reason.value = '';
        },
    });
}

/** Five characters is the server's floor; said here so the button explains itself. */
const reasonIsUsable = computed(() => reason.value.trim().length >= 5);
</script>

<template>
    <div class="space-y-4">
        <!-- A code, translated here. The domain's English names internal
             concepts — one of its messages carries a class name — and is
             written for a log. -->
        <AppAlert v-if="refusal !== null" tone="danger" :title="trans(`ui.catalog.identifier_refusal.${refusal}`)" />

        <p v-if="identifiers.length === 0" class="text-small text-muted">
            {{ trans('ui.catalog.no_identifiers') }}
        </p>

        <ul v-else class="divide-y divide-border">
            <li
                v-for="identifier in identifiers"
                :key="identifier.uuid"
                class="flex flex-wrap items-center justify-between gap-2 py-2"
            >
                <span class="flex flex-wrap items-center gap-2">
                    <span class="text-caption text-muted">
                        {{ identifier.type
                        }}<template v-if="identifier.namespace"> · {{ identifier.namespace }}</template>
                    </span>
                    <CodeValue :value="identifier.value" />
                    <span class="text-caption text-muted">
                        {{ trans(`ui.catalog.identifier_source.${identifier.source}`) }}
                    </span>
                    <span v-if="identifier.is_authoritative" class="text-caption text-success">
                        {{ trans('ui.catalog.authoritative') }}
                    </span>
                </span>

                <AppButton
                    v-if="canAssign"
                    variant="ghost"
                    @click="revoking = identifier"
                >
                    {{ trans('ui.catalog.revoke_identifier') }}
                </AppButton>
            </li>
        </ul>

        <form v-if="canAssign && assignableTypes.length > 0" class="grid gap-3 sm:grid-cols-3" @submit.prevent="assign">
            <!-- FormField provides the id and aria wiring to the control
                 itself; nothing here binds them by hand. -->
            <FormField :label="trans('ui.catalog.identifier_type_label')" :error="assignForm.errors.type">
                <SelectInput v-model="assignForm.type" :options="typeOptions" />
            </FormField>

            <FormField
                :label="trans('ui.catalog.identifier_value')"
                :error="assignForm.errors.value"
                :hint="trans('ui.catalog.identifier_value_hint')"
            >
                <TextInput v-model="assignForm.value" />
            </FormField>

            <div class="flex items-end">
                <AppButton type="submit" variant="secondary" :loading="assignForm.processing">
                    {{ trans('ui.catalog.assign_identifier') }}
                </AppButton>
            </div>
        </form>

        <ConfirmDialog
            :open="revoking !== null"
            :title="trans('ui.catalog.revoke_identifier')"
            :description="trans('ui.catalog.revoke_identifier_description')"
            :confirm-label="trans('ui.catalog.revoke_identifier_confirm')"
            :busy="busy"
            destructive
            @cancel="revoking = null"
            @confirm="reasonIsUsable ? confirmRevoke() : undefined"
        >
            <FormField :label="trans('ui.catalog.revoke_reason')">
                <TextareaInput v-model="reason" :rows="3" />
            </FormField>

            <p v-if="!reasonIsUsable" class="mt-2 text-small text-muted">
                {{ trans('ui.catalog.revoke_reason_required') }}
            </p>
        </ConfirmDialog>
    </div>
</template>
