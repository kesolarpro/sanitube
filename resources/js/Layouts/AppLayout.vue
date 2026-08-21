<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AppSidebar from '@/Components/Layout/AppSidebar.vue';
import AppAlert from '@/Components/Ui/AppAlert.vue';
import { useModalSurface } from '@/Composables/useModalSurface';
import { useTheme } from '@/Composables/useTheme';
import { trans } from '@/Support/i18n';
import type { SharedProps } from '@/Types/inertia';

/**
 * The application shell.
 *
 * Desktop keeps the sidebar fixed; below `lg` it becomes a drawer. One
 * breakpoint rather than three states, because a collapsed icon-only rail is a
 * fourth thing to design and test for very little gain on a tool people use on
 * a laptop.
 *
 * The layout is persistent across Inertia navigations, so the sidebar is not
 * rebuilt and its scroll position survives moving between screens.
 */
const page = usePage<SharedProps>();
const { preference, apply } = useTheme();
const drawerOpen = ref(false);
const drawer = ref<HTMLElement | null>(null);

onMounted(apply);

/*
 * The drawer declares `role="dialog" aria-modal="true"`, so it owes the page
 * modal behaviour: focus in, focus trapped, Escape to close, focus back to the
 * trigger, background locked and inert. All of it comes from the same
 * composable AppModal uses.
 *
 * Focus returns to whatever was focused at open time, which is the menu button
 * — the only control that can open the drawer.
 */
const { onKeydown: onDrawerKeydown } = useModalSurface(
    () => drawerOpen.value,
    drawer,
    () => closeDrawer(),
);

/*
 * `router.on` returns its own unsubscribe function. AppLayout is persistent
 * across Inertia navigations, but persistent is not permanent: a full page
 * visit or a layout swap remounts it, and every remount would add another
 * listener to the same global router. The leak is invisible until the drawer
 * starts closing several times per navigation.
 */
const stopNavigationListener = router.on('navigate', () => {
    drawerOpen.value = false;
});

onBeforeUnmount(() => {
    stopNavigationListener();
});

function closeDrawer(): void {
    drawerOpen.value = false;
}

function openDrawer(): void {
    drawerOpen.value = true;
}

function cycleTheme(): void {
    preference.value = preference.value === 'light' ? 'dark' : preference.value === 'dark' ? 'system' : 'light';
}
</script>

<template>
    <div class="min-h-dvh bg-background">
        <!-- Skip link: the first thing a keyboard user reaches, so they are
             not tabbing through eleven navigation items on every page. -->
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-(--z-overlay) focus:rounded-control focus:bg-surface focus:px-3 focus:py-2 focus:text-small"
        >
            {{ trans('ui.navigation.skip_to_content') }}
        </a>

        <!-- Desktop sidebar -->
        <aside class="fixed inset-y-0 left-0 hidden w-60 border-r border-border bg-surface lg:block">
            <div class="flex h-14 items-center gap-2 border-b border-border px-4">
                <span class="text-card-title text-foreground">{{ page.props.app.name }}</span>
            </div>
            <AppSidebar :items="page.props.navigation" />
        </aside>

        <!-- Mobile drawer -->
        <Teleport to="body">
            <div v-if="drawerOpen" class="lg:hidden" @keydown="onDrawerKeydown">
                <div class="fixed inset-0 z-(--z-drawer) bg-black/40" aria-hidden="true" @click="closeDrawer" />
                <aside
                    ref="drawer"
                    class="fixed inset-y-0 left-0 z-(--z-drawer) w-72 border-r border-border bg-surface"
                    role="dialog"
                    aria-modal="true"
                    tabindex="-1"
                    :aria-label="trans('ui.navigation.primary')"
                >
                    <div class="flex h-14 items-center justify-between border-b border-border px-4">
                        <span class="text-card-title text-foreground">{{ page.props.app.name }}</span>
                        <button
                            type="button"
                            class="rounded p-1.5 text-muted hover:text-foreground"
                            :aria-label="trans('ui.actions.close')"
                            @click="closeDrawer"
                        >
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M18 6 6 18M6 6l12 12" stroke-linecap="round" />
                            </svg>
                        </button>
                    </div>
                    <AppSidebar :items="page.props.navigation" />
                </aside>
            </div>
        </Teleport>

        <div class="lg:pl-60">
            <header
                class="sticky top-0 flex h-14 items-center gap-3 border-b border-border bg-background/85 px-4 backdrop-blur z-(--z-sticky)"
            >
                <button
                    type="button"
                    class="rounded p-1.5 text-muted hover:text-foreground lg:hidden"
                    :aria-label="trans('ui.navigation.open_menu')"
                    :aria-expanded="drawerOpen"
                    @click="openDrawer"
                >
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" />
                    </svg>
                </button>

                <div class="min-w-0 flex-1">
                    <slot name="breadcrumbs" />
                </div>

                <button
                    type="button"
                    class="rounded p-1.5 text-muted hover:text-foreground"
                    :aria-label="trans(`ui.theme.${preference}`)"
                    @click="cycleTheme"
                >
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                        <path
                            v-if="preference === 'dark'"
                            d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        />
                        <g v-else-if="preference === 'light'">
                            <circle cx="12" cy="12" r="4" />
                            <path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4" stroke-linecap="round" />
                        </g>
                        <g v-else>
                            <rect x="3" y="4" width="18" height="13" rx="2" />
                            <path d="M8 21h8" stroke-linecap="round" />
                        </g>
                    </svg>
                </button>

                <span v-if="page.props.auth.user" class="hidden text-small text-muted sm:inline">
                    {{ page.props.auth.user.name }}
                </span>

                <form method="POST" action="/logout">
                    <input type="hidden" name="_token" :value="page.props.csrf_token" />
                    <button type="submit" class="rounded px-2 py-1.5 text-small text-muted hover:text-foreground">
                        {{ trans('identity.auth.sign_out') }}
                    </button>
                </form>
            </header>

            <main id="main" class="mx-auto w-full max-w-[90rem] p-4 sm:p-6">
                <!-- UI-006. The confirmation for a write that worked.
                     Translated here rather than flashed as a sentence, so a
                     Portuguese reader is told in Portuguese that a release
                     they cannot take back has been handed over. -->
                <AppAlert v-if="page.props.flash.status" tone="success" class="mb-4">
                    {{ trans(`ui.flash.${page.props.flash.status}`) }}
                </AppAlert>
                <AppAlert v-if="page.props.flash.success" tone="success" class="mb-4">
                    {{ page.props.flash.success }}
                </AppAlert>
                <AppAlert v-if="page.props.flash.error" tone="danger" class="mb-4">
                    {{ page.props.flash.error }}
                </AppAlert>

                <slot />
            </main>
        </div>
    </div>
</template>
