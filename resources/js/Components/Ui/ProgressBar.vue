<script setup lang="ts">
import { computed } from 'vue';

/**
 * Determinate progress.
 *
 * A real `role="progressbar"` with its min, max and now values, so the figure
 * is available to a screen reader rather than implied by a coloured rectangle.
 */
const props = defineProps<{ value: number; max: number; label: string }>();

const percent = computed(() => (props.max <= 0 ? 0 : Math.min(100, Math.round((props.value / props.max) * 100))));
</script>

<template>
    <div
        role="progressbar"
        :aria-valuenow="value"
        :aria-valuemin="0"
        :aria-valuemax="max"
        :aria-label="label"
        class="h-1.5 w-full overflow-hidden rounded-pill bg-surface-sunken"
    >
        <div
            class="h-full rounded-pill bg-accent transition-[width] duration-(--motion-base) ease-(--motion-ease)"
            :style="{ width: `${percent}%` }"
        />
    </div>
</template>
