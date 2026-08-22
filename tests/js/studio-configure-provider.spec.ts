import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Studio from '@/Pages/Studio/Index.vue';
import type { StudioOverview } from '@/Types/studio';
import { sharedProps } from './support/inertia';

vi.mock('@inertiajs/vue3', () => import('./support/inertia'));

/**
 * The way out of an empty studio.
 *
 * CFG-002. The banner has always said the honest thing — most installations
 * have no generation provider, and SaniTube runs entirely without one — and it
 * said it with no way to act on it. The only route from "not configured" to the
 * setting was knowing the URL, on the one screen whose whole subject is that
 * something is unconfigured.
 *
 * Shown only to somebody the settings screen would admit. A link that answers
 * 403 turns "you cannot do this" into "something is broken", which is a worse
 * sentence to leave a member with than no link at all.
 */

function overview(provider: Partial<StudioOverview['provider']> = {}): StudioOverview {
    return {
        may_generate: false,
        provider: { name: null, configured: false, available: false, capabilities: [], ...provider },
        generations: { total: 0, DRAFT: 0, QUEUED: 0, PROCESSING: 0, COMPLETED: 0, FAILED: 0, CANCELLED: 0 },
        rights: { UNKNOWN: 0, ALLOWED: 0, RESTRICTED: 0, PROHIBITED: 0, REVIEW_REQUIRED: 0 },
        projects: 0,
    };
}

function screen(studio: StudioOverview) {
    return mount(Studio, { props: { studio } });
}

function settingsLinks(wrapper: ReturnType<typeof screen>) {
    return wrapper.findAll('a').filter((link) => link.attributes('href') === '/settings');
}

function administers(may: boolean): void {
    sharedProps.auth.user.can.administer = may;
}

describe('the studio when nothing is configured', () => {
    beforeEach(() => {
        sharedProps.translations = {};
        administers(true);
    });

    it('offers the way to configure a provider', () => {
        const wrapper = screen(overview());

        expect(wrapper.text()).toContain('ui.studio.not_configured');
        expect(settingsLinks(wrapper)).toHaveLength(1);
    });

    it('offers it for a provider that is configured and not answering too', () => {
        // A different problem with the same destination: somebody has to go
        // and look at what is set.
        const wrapper = screen(overview({ name: 'acestep', configured: true, available: false }));

        expect(wrapper.text()).toContain('ui.studio.unavailable');
        expect(settingsLinks(wrapper)).toHaveLength(1);
    });

    it('offers nothing to somebody who may not change settings', () => {
        administers(false);

        const wrapper = screen(overview());

        // The banner still says what is true. It just does not point a member
        // at a door that will not open for them.
        expect(wrapper.text()).toContain('ui.studio.not_configured');
        expect(settingsLinks(wrapper)).toHaveLength(0);
    });

    it('says nothing about settings once the provider is working', () => {
        const wrapper = screen(
            overview({ name: 'acestep', configured: true, available: true, capabilities: ['TEXT_TO_MUSIC'] }),
        );

        expect(wrapper.text()).toContain('ui.studio.available');
        expect(settingsLinks(wrapper)).toHaveLength(0);
    });
});
