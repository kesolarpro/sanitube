import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Settings from '@/Pages/Settings/Index.vue';
import type { SettingsOverview } from '@/Types/settings';
import { sharedProps } from './support/inertia';

vi.mock('@inertiajs/vue3', () => import('./support/inertia'));

/**
 * The one control on the settings page that reaches anything.
 *
 * CFG-001. What these hold is not that the button works — the probe itself is
 * tested against a real store in `ConnectionTestTest` — but that the *browser*
 * never gets to say what is reached. The request carries the word the server
 * put in `probe` and nothing else; a page that let a caller compose a target
 * would be a page that fetches whatever anyone asks it to, from inside the
 * network the platform lives in.
 */

function overview(): SettingsOverview {
    return {
        application: {
            name: 'SaniTube',
            environment: 'production',
            debug: false,
            locale: 'en',
            locales: ['en'],
            config_cached: true,
        },
        sections: [
            {
                key: 'storage',
                selected: 'local',
                known: true,
                options: ['local'],
                settings: [],
                secrets: [],
                probe: 'storage',
                complete: true,
            },
            {
                key: 'api',
                selected: 'v1',
                known: true,
                options: [],
                settings: [],
                secrets: [],
                probe: null,
                complete: true,
            },
        ],
    };
}

function screen() {
    return mount(Settings, { props: { settings: overview(), writable: [] } });
}

function testButtons(wrapper: ReturnType<typeof screen>) {
    return wrapper.findAll('button').filter((button) => button.text() === 'ui.settings.test_connection');
}

/** The only test button on this screen, named rather than indexed. */
function theTestButton(wrapper: ReturnType<typeof screen>) {
    const buttons = testButtons(wrapper);

    expect(buttons).toHaveLength(1);

    return buttons[0]!;
}

describe('the connection test', () => {
    beforeEach(() => {
        sharedProps.translations = {};
        document.head.innerHTML = '<meta name="csrf-token" content="a-token">';
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('sends the word the server named and never an address', async () => {
        const calls: { url: string; body: string }[] = [];

        vi.stubGlobal(
            'fetch',
            vi.fn((url: string, init: { body: string }) => {
                calls.push({ url, body: init.body });

                return Promise.resolve({
                    ok: true,
                    json: () => Promise.resolve({ status: 'CONNECTED', checks: [] }),
                });
            }),
        );

        await theTestButton(screen()).trigger('click');

        expect(calls).toHaveLength(1);

        const sent = calls[0]!;

        expect(sent.url).toBe('/settings/test');

        // The whole point. Not a URL, not a bucket, not an endpoint the page
        // assembled — the closed word the payload arrived carrying.
        expect(JSON.parse(sent.body)).toEqual({ target: 'storage' });
    });

    it('runs one probe however many times the button is pressed', async () => {
        let started = 0;

        vi.stubGlobal(
            'fetch',
            vi.fn(() => {
                started += 1;

                // Never resolves, which is what a slow bucket looks like from
                // here. The button is disabled once Vue re-renders — and the
                // presses that matter are the ones before it does.
                return new Promise(() => undefined);
            }),
        );

        const wrapper = screen();
        const button = theTestButton(wrapper);

        await Promise.all([button.trigger('click'), button.trigger('click'), button.trigger('click')]);

        // A storage probe writes and deletes a real object. Three of them from
        // an impatient double-click is three round trips somebody pays for.
        expect(started).toBe(1);
    });

    it('offers no button for a section with nothing to reach', () => {
        const wrapper = screen();

        // Two sections, one probe. A button on the API section would be a
        // button that has to invent what it tests.
        expect(testButtons(wrapper)).toHaveLength(1);
    });

    it('says nothing about a request that never came back', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => Promise.reject(new Error('getaddrinfo ENOTFOUND bucket.internal.example'))),
        );

        const wrapper = screen();

        await theTestButton(wrapper).trigger('click');
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('ui.settings.probe_unreachable');

        // A rejected fetch quotes the host it could not resolve. The settings
        // page is the most screenshotted page in any application.
        expect(wrapper.text()).not.toContain('bucket.internal.example');
        expect(wrapper.text()).not.toContain('ENOTFOUND');
    });

    it('shows the checks a probe answered with, as the server worded them', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve({
                    ok: true,
                    json: () =>
                        Promise.resolve({
                            status: 'DEGRADED',
                            checks: [{ name: 'signed_read', outcome: 'skipped', detail: 'Not supported here.' }],
                        }),
                }),
            ),
        );

        const wrapper = screen();

        await theTestButton(wrapper).trigger('click');
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        // Degraded is its own word: a store without presigned uploads works,
        // and calling it broken sends somebody hunting a fault that is a
        // capability.
        expect(wrapper.text()).toContain('ui.settings.probe.DEGRADED');
        expect(wrapper.text()).toContain('signed_read');
        expect(wrapper.text()).toContain('Not supported here.');
    });
});
