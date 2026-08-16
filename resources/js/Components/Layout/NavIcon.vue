<script setup lang="ts">
/**
 * The icon set, inline.
 *
 * Inline paths rather than an icon package: the sidebar needs eleven glyphs,
 * and a dependency that ships two thousand is weight a shared host pays for on
 * every load. `aria-hidden` throughout — every icon here sits beside a text
 * label, so announcing it would just repeat the label.
 */
defineProps<{ name: string }>();

const PATHS: Record<string, string> = {
    dashboard: 'M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z',
    studio: 'M9 18V5l12-2v13M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm12-2a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    library: 'M4 19V5a2 2 0 0 1 2-2h9l5 5v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z',
    ingestion: 'M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2',
    catalog: 'M4 6h16M4 12h16M4 18h10',
    assets: 'M4 7 12 3l8 4v10l-8 4-8-4V7Zm8 4 8-4m-8 4-8-4m8 4v10',
    releases: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 6a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z',
    distribution: 'M12 3v6m0 0-3-3m3 3 3-3M5 13a7 7 0 0 0 14 0M5 13H3m16 0h2M7 21h10',
    rights: 'M12 3 4 6v6c0 4.4 3.4 8.4 8 9 4.6-.6 8-4.6 8-9V6l-8-3Z',
    analytics: 'M4 20V10m6 10V4m6 16v-7m4 7V8',
    jobs: 'M12 8v4l3 2m6-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    system: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm7.4-3a7.4 7.4 0 0 0-.1-1.2l2-1.6-2-3.4-2.4 1a7.4 7.4 0 0 0-2-1.2L14.5 2h-4l-.4 2.6c-.7.3-1.4.7-2 1.2l-2.4-1-2 3.4 2 1.6a7.4 7.4 0 0 0 0 2.4l-2 1.6 2 3.4 2.4-1c.6.5 1.3.9 2 1.2l.4 2.6h4l.4-2.6c.7-.3 1.4-.7 2-1.2l2.4 1 2-3.4-2-1.6c.1-.4.1-.8.1-1.2Z',
    settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0-12v3m0 12v3M3 12h3m12 0h3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1',
};
</script>

<template>
    <svg
        class="size-4 shrink-0"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.7"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        <path :d="PATHS[name] ?? PATHS.dashboard" />
    </svg>
</template>
