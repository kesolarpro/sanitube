<script setup lang="ts">
import { computed, useId } from 'vue';
import { provideField } from '@/Support/formField';

/**
 * A label, a control, and the error that belongs to it — wired together.
 *
 * The wiring is the point. `for`/`id` is what lets a screen reader announce
 * the label when the control is focused, and `aria-describedby` is what makes
 * it announce the error too.
 *
 * Both used to be *offered* as slot props, and sixty-one of the platform's
 * sixty-five fields never bound them — which is a mechanism problem rather
 * than sixty-one careless authors. A label pointing at nothing is silent: the
 * screen looks right and a screen-reader user hears "edit text, blank". So the
 * wiring is now also **provided**, and the controls pick it up themselves. The
 * slot props stay for the handful of screens that want to place them by hand.
 */
const props = defineProps<{ label: string; error?: string | null; hint?: string; required?: boolean }>();

const id = useId();
const describedBy = `${id}-description`;

provideField({
    id,
    // Only when there is something to describe. Pointing at an element that
    // does not exist makes some screen readers announce nothing at all.
    describedBy: computed(() => (props.hint || props.error ? describedBy : undefined)),
    invalid: computed(() => Boolean(props.error)),
});
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
