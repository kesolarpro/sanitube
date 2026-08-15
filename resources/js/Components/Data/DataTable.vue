<script setup lang="ts">
/**
 * A table that is a table.
 *
 * Real <table>, <thead>, <th scope="col"> — not a grid of divs. A screen
 * reader announces "column 3 of 8, Duration" from the semantics alone, and no
 * amount of ARIA on divs reproduces that reliably.
 *
 * Horizontal overflow scrolls inside the component. SaniTube's catalogue table
 * has eight columns and has to survive a 375px phone; letting the page scroll
 * sideways instead breaks every other element on it.
 */
defineProps<{ caption: string; busy?: boolean }>();
</script>

<template>
    <div class="overflow-x-auto" :aria-busy="busy || undefined">
        <table class="w-full border-collapse text-body">
            <!-- Visually hidden rather than absent: the caption is what tells
                 a screen-reader user what this table is before they enter it. -->
            <caption class="sr-only">{{ caption }}</caption>
            <thead>
                <tr class="border-b border-border text-left">
                    <slot name="head" />
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <slot />
            </tbody>
        </table>
    </div>
</template>
