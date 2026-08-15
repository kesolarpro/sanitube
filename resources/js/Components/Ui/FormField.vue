<script setup lang="ts">
import { useId } from 'vue';

/**
 * A label, a control, and the error that belongs to it — wired together.
 *
 * The wiring is the point. `for`/`id` is what lets a screen reader announce
 * the label when the control is focused, and `aria-describedby` is what makes
 * it announce the error too. Both are generated here so no screen can forget
 * them, which is exactly what happens when each form does it by hand.
 */
defineProps<{ label: string; error?: string | null; hint?: string; required?: boolean }>();

const id = useId();
const describedBy = `${id}-description`;
</script>

<template>
    <div class="space-y-1.5">
        <label :for="id" class="block text-small font-medium text-foreground">
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true">*</span>
        </label>

        <slot :id="id" :described-by="hint || error ? describedBy : undefined" :invalid="Boolean(error)" />

        <p v-if="error" :id="describedBy" class="text-caption text-danger" role="alert">{{ error }}</p>
        <p v-else-if="hint" :id="describedBy" class="text-caption text-muted">{{ hint }}</p>
    </div>
</template>
