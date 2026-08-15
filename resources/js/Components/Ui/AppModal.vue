<script setup lang="ts">
import { ref } from 'vue';
import { useModalSurface } from '@/Composables/useModalSurface';
import { trans } from '@/Support/i18n';

/**
 * A dialog that behaves like one.
 *
 * Focus moves in, is trapped, and returns; Escape closes; the page behind stops
 * scrolling and goes `inert`. None of that is here — it is in `useModalSurface`,
 * shared with the layout's mobile drawer, because two implementations of a
 * focus trap is one implementation and one liability.
 */
const props = defineProps<{ open: boolean; title: string; description?: string }>();
const emit = defineEmits<{ close: [] }>();

const panel = ref<HTMLElement | null>(null);

const { onKeydown } = useModalSurface(
    () => props.open,
    panel,
    () => emit('close'),
);
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
