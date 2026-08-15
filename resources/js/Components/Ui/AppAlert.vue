<script setup lang="ts">
import { computed } from 'vue';

/**
 * An inline message about the page, not about an action.
 *
 * `role` is chosen by tone: `alert` interrupts a screen reader, `status` does
 * not. Marking every notice as an alert is how assistive technology users
 * learn to tune them out, so only danger and warning interrupt.
 */
const props = withDefaults(defineProps<{ tone?: 'info' | 'success' | 'warning' | 'danger'; title?: string }>(), {
    tone: 'info',
});

const classes = computed(
    () =>
        ({
            info: 'bg-info-subtle text-info',
            success: 'bg-success-subtle text-success',
            warning: 'bg-warning-subtle text-warning',
            danger: 'bg-danger-subtle text-danger',
        })[props.tone],
);

const role = computed(() => (props.tone === 'danger' || props.tone === 'warning' ? 'alert' : 'status'));
</script>

<template>
    <div class="rounded-control px-3 py-2.5 text-small" :class="classes" :role="role">
        <p v-if="title" class="font-medium">{{ title }}</p>
        <div :class="title ? 'mt-0.5 opacity-90' : ''"><slot /></div>
    </div>
</template>
