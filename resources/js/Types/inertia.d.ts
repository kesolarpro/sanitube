import type { PageProps as InertiaPageProps } from '@inertiajs/core';

/**
 * What every SaniTube page receives from the server.
 *
 * Shared once in `HandleInertiaRequests` rather than fetched per page: the
 * navigation, the signed-in user and the locale are needed on every screen,
 * and three extra requests on every navigation is how a fast interface stops
 * being one.
 */
export interface AuthUser {
    uuid: string;
    name: string;
    email: string;
    role: 'OWNER' | 'ADMIN' | 'MEMBER';
    can: {
        catalogue: boolean;
        distribute: boolean;
        administer: boolean;
    };
}

export interface NavigationItem {
    key: string;
    label: string;
    href: string | null;
    icon: string;
    /** False for sections that exist in the map but have no screen yet. */
    available: boolean;
    children?: NavigationItem[];
}

export interface FlashMessages {
    success: string | null;
    error: string | null;
}

export interface SharedProps extends InertiaPageProps {
    app: {
        name: string;
        locale: string;
        locales: Array<{ code: string; label: string }>;
    };
    auth: { user: AuthUser | null };
    navigation: NavigationItem[];
    flash: FlashMessages;
    /** Translation lines for the current locale, keyed by dotted path. */
    translations: Record<string, string>;
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $page: { props: SharedProps };
    }
}
