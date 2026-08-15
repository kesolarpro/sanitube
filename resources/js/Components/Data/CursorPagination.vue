<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from '@/Support/i18n';

/**
 * Previous and next, and nothing else.
 *
 * The API paginates by cursor, so there is no page count to show and no "jump
 * to page 7" to offer — a cursor names a position, not an index. Rendering
 * numbered pages here would mean inventing a total the server never computed,
 * which is exactly the kind of made-up figure this interface must not display.
 */
defineProps<{ prev: string | null; next: string | null }>();
</script>

<template>
    <nav v-if="prev || next" class="flex items-center justify-end gap-2 px-3 py-2" :aria-label="trans('ui.pagination.label')">
        <Link
            v-if="prev"
            :href="prev"
            class="rounded-control border border-border px-3 py-1.5 text-small text-foreground hover:bg-surface-sunken"
            preserve-scroll
        >
            {{ trans('ui.pagination.previous') }}
        </Link>
        <Link
            v-if="next"
            :href="next"
            class="rounded-control border border-border px-3 py-1.5 text-small text-foreground hover:bg-surface-sunken"
            preserve-scroll
        >
            {{ trans('ui.pagination.next') }}
        </Link>
    </nav>
</template>
