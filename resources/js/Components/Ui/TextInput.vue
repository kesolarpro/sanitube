<script setup lang="ts">
/**
 * A single-line control.
 *
 * `aria-invalid` is driven by the parent {@link FormField} rather than by a
 * red border alone: colour is not available to everyone, and a border that is
 * the only signal of an error is an error some users never see.
 */
defineProps<{ id?: string; describedBy?: string; invalid?: boolean; type?: string; placeholder?: string; disabled?: boolean }>();

const model = defineModel<string | number | null>();
</script>

<template>
    <input
        :id="id"
        v-model="model"
        :type="type ?? 'text'"
        :placeholder="placeholder"
        :disabled="disabled"
        :aria-invalid="invalid || undefined"
        :aria-describedby="describedBy"
        class="h-9 w-full rounded-control border bg-surface px-3 text-body text-foreground placeholder:text-subtle disabled:opacity-50"
        :class="invalid ? 'border-danger' : 'border-border'"
    />
</template>
