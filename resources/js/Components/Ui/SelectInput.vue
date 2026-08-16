<script setup lang="ts">
import { computed } from 'vue';
import { useField } from '@/Support/formField';
/**
 * A native <select>.
 *
 * Native rather than a custom listbox, deliberately. A hand-built dropdown has
 * to re-implement keyboard navigation, type-ahead, screen-reader semantics and
 * the mobile picker — and almost every one gets at least one of those wrong.
 * The platform control is already correct in six languages.
 */
const props = defineProps<{
    id?: string;
    describedBy?: string;
    invalid?: boolean;
    disabled?: boolean;
    options: Array<{ value: string; label: string }>;
    placeholder?: string;
}>();
/**
 * Wiring picked up from the enclosing {@link FormField}, when there is one.
 *
 * A control outside a field keeps working exactly as before; an explicitly
 * passed prop still wins, for the rare screen that places its own.
 */
const field = useField();
const fieldId = computed(() => props.id ?? field?.id);
const fieldDescribedBy = computed(() => props.describedBy ?? field?.describedBy.value);
// `||`, not `??`. Vue casts an absent Boolean prop to `false` rather than
// leaving it undefined, so `??` never falls through to the field and
// `aria-invalid` would be silently absent on every erroring control in the
// platform. Either source saying "invalid" is enough, which is also the
// behaviour worth having: a field with an error is invalid whatever the
// control was told.
const fieldInvalid = computed(() => props.invalid || field?.invalid.value || false);


const model = defineModel<string | null>();
</script>

<template>
    <select
        :id="fieldId"
        v-model="model"
        :disabled="disabled"
        :aria-invalid="fieldInvalid || undefined"
        :aria-describedby="fieldDescribedBy"
        class="h-9 w-full rounded-control border bg-surface px-3 text-body text-foreground disabled:opacity-50"
        :class="fieldInvalid ? 'border-danger' : 'border-border'"
    >
        <option v-if="placeholder" :value="null">{{ placeholder }}</option>
        <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
    </select>
</template>
