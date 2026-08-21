import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AppLayout from '@/Layouts/AppLayout.vue';
import { resetModalSurfaces } from '@/Composables/useModalSurface';
import { navigationListeners, sharedProps } from './support/inertia';
import { flush, openDialog, pressKeyInDialog } from './support/environment';

vi.mock('@inertiajs/vue3', () => import('./support/inertia'));

/**
 * The mobile drawer, which declares itself a modal dialog and must therefore
 * behave like one — and the layout's own lifecycle, which must leave nothing
 * behind when it goes.
 */

function menuButton(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAll('button').filter((button) => button.attributes('aria-expanded') !== undefined)[0];
}

describe('application shell', () => {
    beforeEach(() => {
        resetModalSurfaces();
        navigationListeners.clear();
        sharedProps.flash = {};
        sharedProps.translations = {};
        document.body.innerHTML = '';
        document.body.style.overflow = '';
    });

    afterEach(() => {
        resetModalSurfaces();
    });

    /**
     * UI-006. The confirmation for a write that worked.
     *
     * Thirty-six controllers were flashing a status code that the shell never
     * received and never rendered, so every successful write in the interface
     * redirected in silence. The code carries the *identity* of what happened;
     * the shell is where it becomes a sentence in the reader's language.
     */
    it('announces a write that worked, in the reader\'s language', () => {
        sharedProps.flash = { status: 'release.ready' };
        sharedProps.translations = { 'ui.flash.release.ready': 'Marked ready for distribution.' };

        const wrapper = mount(AppLayout, { attachTo: document.body });

        expect(wrapper.text()).toContain('Marked ready for distribution.');
        // The code itself never reaches the reader.
        expect(wrapper.text()).not.toContain('release.ready');
    });

    it('says nothing when nothing was flashed', () => {
        const wrapper = mount(AppLayout, { attachTo: document.body });

        expect(wrapper.text()).not.toContain('Marked ready');
    });

    it('moves focus into the drawer when it opens', async () => {
        const wrapper = mount(AppLayout, { attachTo: document.body });

        await menuButton(wrapper)?.trigger('click');
        await flush();

        const drawer = openDialog();

        expect(drawer).not.toBeNull();
        expect(drawer?.contains(document.activeElement)).toBe(true);

        wrapper.unmount();
    });

    it('closes the drawer on Escape', async () => {
        const wrapper = mount(AppLayout, { attachTo: document.body });

        await menuButton(wrapper)?.trigger('click');
        expect(openDialog()).not.toBeNull();

        pressKeyInDialog('Escape');
        await flush();

        expect(openDialog()).toBeNull();

        wrapper.unmount();
    });

    it('returns focus to the menu trigger after the drawer closes', async () => {
        const wrapper = mount(AppLayout, { attachTo: document.body });

        const trigger = menuButton(wrapper);
        // See the modal spec: jsdom does not focus a button on click.
        (trigger?.element as HTMLElement | undefined)?.focus();
        await trigger?.trigger('click');
        await flush();

        pressKeyInDialog('Escape');
        await flush();
        await flush();

        expect(document.activeElement).toBe(trigger?.element);

        wrapper.unmount();
    });

    it('locks and restores background scroll around the drawer', async () => {
        const wrapper = mount(AppLayout, { attachTo: document.body });

        await menuButton(wrapper)?.trigger('click');
        expect(document.body.style.overflow).toBe('hidden');

        pressKeyInDialog('Escape');
        await flush();
        expect(document.body.style.overflow).toBe('');

        wrapper.unmount();
    });

    it('restores background scroll when unmounted with the drawer open', async () => {
        const wrapper = mount(AppLayout, { attachTo: document.body });

        await menuButton(wrapper)?.trigger('click');
        expect(document.body.style.overflow).toBe('hidden');

        wrapper.unmount();

        expect(document.body.style.overflow).toBe('');
    });

    it('does not accumulate navigation listeners across remounts', async () => {
        // AppLayout is persistent across Inertia visits, but persistent is not
        // permanent — a full page load remounts it. Without the unsubscribe,
        // each remount leaves its predecessor's listener registered on the
        // global router, and the drawer starts closing several times per
        // navigation.
        expect(navigationListeners.size).toBe(0);

        for (let visit = 0; visit < 3; visit += 1) {
            const wrapper = mount(AppLayout, { attachTo: document.body });

            expect(navigationListeners.size).toBe(1);

            wrapper.unmount();

            expect(navigationListeners.size).toBe(0);
        }
    });

    it('closes the drawer when a navigation happens', async () => {
        const wrapper = mount(AppLayout, { attachTo: document.body });

        await menuButton(wrapper)?.trigger('click');
        expect(openDialog()).not.toBeNull();

        navigationListeners.forEach((listener) => listener());
        await flush();

        expect(openDialog()).toBeNull();

        wrapper.unmount();
    });
});
