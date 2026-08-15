<script setup lang="ts">
/**
 * A placeholder while something loads.
 *
 * `aria-hidden`, always. A skeleton is a visual reassurance; announcing a grid
 * of grey rectangles to a screen reader is noise. The surrounding region
 * carries `aria-busy` instead, which is the part that actually communicates.
 */
withDefaults(defineProps<{ lines?: number }>(), { lines: 3 });
</script>

<template>
    <div class="space-y-2" aria-hidden="true">
        <div
            v-for="line in lines"
            :key="line"
            class="h-4 animate-pulse rounded bg-surface-sunken"
            :style="{ width: `${100 - line * 8}%` }"
        />
    </div>
</template>
