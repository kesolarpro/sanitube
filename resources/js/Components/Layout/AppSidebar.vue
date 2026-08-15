<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import NavIcon from './NavIcon.vue';
import { trans } from '@/Support/i18n';
import type { NavigationItem, SharedProps } from '@/Types/inertia';

/**
 * The primary navigation.
 *
 * The tree comes from the server, not from a constant in the bundle. Which
 * sections a person can see depends on their role, and a client-side list
 * filtered in JavaScript is a list an attacker reads in full — so the server
 * sends only what this user may reach.
 *
 * Sections without a screen yet are rendered as disabled items rather than
 * hidden. A navigation that grows as features land is disorienting; one that
 * shows the whole shape of the product and marks what is not ready is honest
 * and stable.
 */
defineProps<{ items: NavigationItem[] }>();

const page = usePage<SharedProps>();

function isCurrent(href: string | null): boolean {
    if (!href) {
        return false;
    }

    // Inertia's own `page.url` rather than window.location: it is the URL of
    // the page currently rendered, which during a navigation is the one being
    // navigated *to*. Reading the browser location instead leaves the previous
    // item highlighted until the transition finishes.
    const path = page.url.split('?')[0] ?? '/';

    return path === href || (href !== '/' && path.startsWith(`${href}/`));
}
</script>

<template>
    <nav class="flex h-full flex-col gap-1 overflow-y-auto p-3" :aria-label="trans('ui.navigation.primary')">
        <template v-for="item in items" :key="item.key">
            <!-- A group heading: a section with children is a label, not a
                 destination, so it must not be a link that goes nowhere. -->
            <p v-if="item.children?.length" class="px-2 pt-4 pb-1 text-caption font-medium tracking-wide text-subtle uppercase">
                {{ item.label }}
            </p>

            <Link
                v-else-if="item.available && item.href"
                :href="item.href"
                class="flex items-center gap-2.5 rounded-control px-2.5 py-2 text-small transition-colors duration-(--motion-fast)"
                :class="
                    isCurrent(item.href)
                        ? 'bg-accent-subtle font-medium text-accent'
                        : 'text-muted hover:bg-surface-sunken hover:text-foreground'
                "
                :aria-current="isCurrent(item.href) ? 'page' : undefined"
            >
                <NavIcon :name="item.icon" />
                {{ item.label }}
            </Link>

            <span
                v-else
                class="flex cursor-default items-center gap-2.5 rounded-control px-2.5 py-2 text-small text-subtle opacity-60"
                :title="trans('ui.navigation.not_available')"
            >
                <NavIcon :name="item.icon" />
                {{ item.label }}
                <span class="sr-only">— {{ trans('ui.navigation.not_available') }}</span>
            </span>

            <template v-for="child in item.children ?? []" :key="child.key">
                <Link
                    v-if="child.available && child.href"
                    :href="child.href"
                    class="flex items-center gap-2.5 rounded-control px-2.5 py-2 text-small transition-colors duration-(--motion-fast)"
                    :class="
                        isCurrent(child.href)
                            ? 'bg-accent-subtle font-medium text-accent'
                            : 'text-muted hover:bg-surface-sunken hover:text-foreground'
                    "
                    :aria-current="isCurrent(child.href) ? 'page' : undefined"
                >
                    <NavIcon :name="child.icon" />
                    {{ child.label }}
                </Link>
                <span
                    v-else
                    class="flex cursor-default items-center gap-2.5 rounded-control px-2.5 py-2 text-small text-subtle opacity-60"
                >
                    <NavIcon :name="child.icon" />
                    {{ child.label }}
                    <span class="sr-only">— {{ trans('ui.navigation.not_available') }}</span>
                </span>
            </template>
        </template>
    </nav>
</template>
