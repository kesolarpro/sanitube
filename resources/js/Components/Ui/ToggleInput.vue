<script setup lang="ts">
/**
 * A switch.
 *
 * A real <button role="switch">, not a styled checkbox: `aria-checked` is what
 * makes a screen reader say "on" rather than "checked", and the distinction
 * matters for a setting that takes effect immediately.
 */
defineProps<{ label: string; disabled?: boolean }>();

const model = defineModel<boolean>({ default: false });
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="model"
        :aria-label="label"
        :disabled="disabled"
        class="relative inline-flex h-5 w-9 shrink-0 rounded-pill border border-transparent transition-colors duration-(--motion-fast) disabled:opacity-50"
        :class="model ? 'bg-accent' : 'bg-border-strong'"
        @click="model = !model"
    >
        <span
            class="pointer-events-none m-0.5 size-4 rounded-full bg-white shadow-raised transition-transform duration-(--motion-fast) ease-(--motion-ease)"
            :class="model ? 'translate-x-4' : 'translate-x-0'"
        />
    </button>
</template>
