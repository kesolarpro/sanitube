<script setup lang="ts">
/**
 * The question could not be asked.
 *
 * Separate from {@link EmptyState} on purpose — see that component. This one
 * carries a retry, because the usual cause is transient.
 */
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="flex flex-col items-center justify-center gap-2 px-6 py-14 text-center" role="alert">
        <div class="rounded-full bg-danger-subtle p-3 text-danger" aria-hidden="true">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M12 8v5m0 3h.01M10.3 3.9 2.4 17a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>
        <p class="text-card-title text-foreground">{{ title }}</p>
        <p v-if="description" class="max-w-sm text-small text-muted">{{ description }}</p>
        <div v-if="$slots.action" class="mt-2"><slot name="action" /></div>
    </div>
</template>
