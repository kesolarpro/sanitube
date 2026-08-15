<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { count } from '@/Support/format';
import type { SharedProps } from '@/Types/inertia';

/**
 * One number, large enough to read across a desk.
 *
 * `value` is `number | null`, and null renders an em dash rather than a zero.
 * The distinction is load-bearing throughout SaniTube: "no failed jobs" and
 * "we could not count the failed jobs" are different facts, and a dashboard
 * that shows 0 for both is a dashboard that hides an outage.
 */
const props = defineProps<{ label: string; value: number | null; href?: string; hint?: string }>();

const page = usePage<SharedProps>();
const formatted = computed(() => count(props.value, page.props.app.locale));
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href"
        class="block rounded-card border border-border bg-surface p-4 shadow-raised"
        :class="href ? 'transition-colors duration-(--motion-fast) hover:border-border-strong' : ''"
    >
        <p class="text-caption text-muted">{{ label }}</p>
        <p class="mt-1 text-metric numeric text-foreground">{{ formatted }}</p>
        <p v-if="hint" class="mt-1 text-caption text-subtle">{{ hint }}</p>
    </component>
</template>
