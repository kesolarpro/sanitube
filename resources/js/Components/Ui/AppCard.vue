<script setup lang="ts">
/**
 * A bounded region of content.
 *
 * Deliberately understated: a single hairline border and almost no shadow.
 * Heavy elevation on every card is what makes an interface look like a demo,
 * and SaniTube's screens are dense — twenty raised cards on one page is noise,
 * not hierarchy.
 */
withDefaults(defineProps<{ padded?: boolean }>(), { padded: true });
</script>

<template>
    <section class="rounded-card border border-border bg-surface shadow-raised">
        <header v-if="$slots.header" class="flex items-center justify-between gap-4 border-b border-border px-4 py-3">
            <div class="min-w-0">
                <h2 class="text-card-title text-foreground"><slot name="header" /></h2>
                <p v-if="$slots.description" class="text-caption text-muted"><slot name="description" /></p>
            </div>
            <div v-if="$slots.actions" class="shrink-0"><slot name="actions" /></div>
        </header>

        <div :class="padded ? 'p-4' : ''"><slot /></div>

        <footer v-if="$slots.footer" class="border-t border-border px-4 py-3"><slot name="footer" /></footer>
    </section>
</template>
