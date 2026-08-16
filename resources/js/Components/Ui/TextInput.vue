<script setup lang="ts">
import { computed } from 'vue';
import { useField } from '@/Support/formField';
/**
 * A single-line control.
 *
 * `aria-invalid` is driven by the parent {@link FormField} rather than by a
 * red border alone: colour is not available to everyone, and a border that is
 * the only signal of an error is an error some users never see.
 */
const props = defineProps<{ id?: string; describedBy?: string; invalid?: boolean; type?: string; placeholder?: string; disabled?: boolean }>();
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


const model = defineModel<string | number | null>();
</script>

<template>
    <input
        :id="fieldId"
        v-model="model"
        :type="type ?? 'text'"
        :placeholder="placeholder"
        :disabled="disabled"
        :aria-invalid="fieldInvalid || undefined"
        :aria-describedby="fieldDescribedBy"
        class="h-9 w-full rounded-control border bg-surface px-3 text-body text-foreground placeholder:text-subtle disabled:opacity-50"
        :class="fieldInvalid ? 'border-danger' : 'border-border'"
    />
</template>
