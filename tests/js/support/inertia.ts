/**
 * A stand-in for the parts of Inertia the shell touches.
 *
 * `trans()` reads the shared props and AppLayout subscribes to the global
 * router, so neither can be mounted without them. Deliberately small: it exists
 * to let the real components run, not to reimplement Inertia.
 *
 * Specs install it with `vi.mock('@inertiajs/vue3', () => import('./support/inertia'))`,
 * so this module's exports *are* the mocked module.
 */

export type NavigationListener = () => void;

/** Every listener currently registered through `router.on` and not yet removed. */
export const navigationListeners = new Set<NavigationListener>();

export const sharedProps = {
    app: { name: 'SaniTube', locale: 'en' },
    auth: { user: { name: 'Owner', role: 'OWNER', can: { administer: true } } },
    navigation: [] as unknown[],
    flash: {} as Record<string, string>,
    translations: {} as Record<string, string>,
    csrf_token: 'test-token',
};

export function usePage(): { props: typeof sharedProps } {
    return { props: sharedProps };
}

/** Every `router.patch` a spec provoked, in order. */
export const patches: { url: string; data: Record<string, unknown> }[] = [];

export const router = {
    patch(url: string, data: Record<string, unknown>): void {
        patches.push({ url, data });
    },

    on(_event: string, handler: NavigationListener): () => void {
        navigationListeners.add(handler);

        // The real `router.on` returns its own unsubscribe function. A mock
        // that returned nothing would make the listener leak this suite is
        // meant to catch impossible to observe.
        return () => {
            navigationListeners.delete(handler);
        };
    },
};

export const Link = { template: '<a><slot /></a>' };
