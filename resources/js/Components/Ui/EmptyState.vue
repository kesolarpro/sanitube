<script setup lang="ts">
/**
 * Nothing here yet — said properly.
 *
 * Distinct from {@link ErrorState}: empty means the query worked and the
 * answer was none, error means the question could not be asked. Collapsing
 * them is how "the database is down" gets shown as "no tracks".
 */
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-2 px-6 py-14 text-center">
        <div class="rounded-full bg-surface-sunken p-3 text-subtle" aria-hidden="true">
            <slot name="icon">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M4 7h16M4 12h16M4 17h10" stroke-linecap="round" />
                </svg>
            </slot>
        </div>
        <p class="text-card-title text-foreground">{{ title }}</p>
        <p v-if="description" class="max-w-sm text-small text-muted">{{ description }}</p>
        <div v-if="$slots.action" class="mt-2"><slot name="action" /></div>
    </div>
</template>
