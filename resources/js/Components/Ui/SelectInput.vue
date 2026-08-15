<script setup lang="ts">
/**
 * A native <select>.
 *
 * Native rather than a custom listbox, deliberately. A hand-built dropdown has
 * to re-implement keyboard navigation, type-ahead, screen-reader semantics and
 * the mobile picker — and almost every one gets at least one of those wrong.
 * The platform control is already correct in six languages.
 */
defineProps<{
    id?: string;
    describedBy?: string;
    invalid?: boolean;
    disabled?: boolean;
    options: Array<{ value: string; label: string }>;
    placeholder?: string;
}>();

const model = defineModel<string | null>();
</script>

<template>
    <select
        :id="id"
        v-model="model"
        :disabled="disabled"
        :aria-invalid="invalid || undefined"
        :aria-describedby="describedBy"
        class="h-9 w-full rounded-control border bg-surface px-3 text-body text-foreground disabled:opacity-50"
        :class="invalid ? 'border-danger' : 'border-border'"
    >
        <option v-if="placeholder" :value="null">{{ placeholder }}</option>
        <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
    </select>
</template>
