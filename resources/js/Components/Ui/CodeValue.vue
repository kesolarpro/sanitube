<script setup lang="ts">
import { ref } from 'vue';
import { trans } from '@/Support/i18n';

/**
 * An identifier a person needs to copy exactly — a uuid, an ISRC, a UPC.
 *
 * Monospaced and tabular so a column of them aligns, and copyable, because the
 * alternative is somebody transcribing `FRZ859900001` into a distributor's
 * form by hand.
 *
 * The copy confirmation is announced politely rather than shown only in
 * colour: a screen-reader user gets no feedback from a tick that appears.
 */
defineProps<{ value: string; label?: string }>();

const copied = ref(false);

async function copy(value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 1600);
    } catch {
        // Clipboard access is refused in plenty of legitimate configurations —
        // an insecure origin, a locked-down browser. The value is still on
        // screen and selectable, so this is not worth an error dialog.
    }
}
</script>

<template>
    <span class="inline-flex items-center gap-1.5">
        <code class="rounded bg-surface-sunken px-1.5 py-0.5 font-mono text-identifier numeric text-muted">{{ value }}</code>
        <button
            type="button"
            class="rounded p-1 text-subtle transition-colors duration-(--motion-fast) hover:text-foreground"
            :aria-label="label ?? trans('ui.actions.copy')"
            @click="copy(value)"
        >
            <svg v-if="!copied" class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <rect x="9" y="9" width="12" height="12" rx="2" />
                <path d="M5 15V5a2 2 0 0 1 2-2h10" stroke-linecap="round" />
            </svg>
            <svg v-else class="size-3.5 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="m20 6-11 11-5-5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
        <span class="sr-only" role="status">{{ copied ? trans('ui.actions.copied') : '' }}</span>
    </span>
</template>
