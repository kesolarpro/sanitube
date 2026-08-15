<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { trans } from '@/Support/i18n';

/**
 * A dialog that behaves like one.
 *
 * Three things a styled <div> does not do, and all three are required:
 *
 *   - **Focus moves in.** Otherwise a keyboard user opens the dialog and their
 *     focus is still behind it, on a page they cannot see.
 *   - **Focus is trapped.** Tab from the last control wraps to the first
 *     rather than escaping into the page underneath.
 *   - **Focus returns.** On close, focus goes back to whatever opened it, so
 *     the user is where they were rather than at the top of the document.
 *
 * Escape closes. `aria-modal` and `role="dialog"` tell assistive technology
 * the rest of the page is inert.
 */
const props = defineProps<{ open: boolean; title: string; description?: string }>();
const emit = defineEmits<{ close: [] }>();

const panel = ref<HTMLElement | null>(null);
const previouslyFocused = ref<HTMLElement | null>(null);

watch(
    () => props.open,
    async (open) => {
        if (open) {
            previouslyFocused.value = document.activeElement as HTMLElement | null;
            await nextTick();
            focusables()[0]?.focus() ?? panel.value?.focus();
        } else {
            previouslyFocused.value?.focus();
        }
    },
);

function focusables(): HTMLElement[] {
    if (!panel.value) {
        return [];
    }

    return Array.from(
        panel.value.querySelectorAll<HTMLElement>(
            'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    );
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        emit('close');

        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const items = focusables();
    const first = items[0];
    const last = items[items.length - 1];

    if (!first || !last) {
        return;
    }

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 flex items-center justify-center p-4 z-(--z-overlay)"
            @keydown="onKeydown"
        >
            <!-- The backdrop is a sibling, not a parent: a click handler on a
                 wrapper also fires for clicks inside the panel. -->
            <div class="absolute inset-0 bg-black/40" aria-hidden="true" @click="emit('close')" />

            <div
                ref="panel"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                tabindex="-1"
                class="relative w-full max-w-lg rounded-card border border-border bg-surface shadow-overlay"
            >
                <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                    <div>
                        <h2 class="text-section-title text-foreground">{{ title }}</h2>
                        <p v-if="description" class="mt-0.5 text-small text-muted">{{ description }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded p-1 text-subtle hover:text-foreground"
                        :aria-label="trans('ui.actions.close')"
                        @click="emit('close')"
                    >
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M18 6 6 18M6 6l12 12" stroke-linecap="round" />
                        </svg>
                    </button>
                </header>

                <div class="px-5 py-4"><slot /></div>

                <footer v-if="$slots.footer" class="flex justify-end gap-2 border-t border-border px-5 py-4">
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>
