import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import AppModal from '@/Components/Ui/AppModal.vue';
import { backgroundIsLocked, resetModalSurfaces } from '@/Composables/useModalSurface';
import { flush, openDialog, pressKeyInDialog } from './support/environment';

vi.mock('@inertiajs/vue3', () => import('./support/inertia'));

/**
 * `role="dialog" aria-modal="true"` is a promise that the rest of the page is
 * unreachable. These are the assertions that the promise is kept.
 *
 * Exercised through the real AppModal rather than the composable alone: a trap
 * that works in isolation but is wired to the wrong element is the failure that
 * actually happens.
 */

/** A page behind the dialog, with something focusable to escape onto. */
const Harness = defineComponent({
    components: { AppModal },
    data() {
        return { open: false };
    },
    template: `
        <div id="app">
            <button id="trigger" @click="open = true">Open</button>
            <button id="behind">Behind</button>
            <AppModal :open="open" title="Confirm" @close="open = false">
                <button id="one">One</button>
                <button id="two">Two</button>
            </AppModal>
        </div>
    `,
});

/** Every focusable control inside the dialog, in tab order. */
function dialogControls(): HTMLElement[] {
    return Array.from(document.querySelectorAll<HTMLElement>('[role="dialog"] button'));
}

describe('modal surface', () => {
    beforeEach(() => {
        resetModalSurfaces();
        document.body.innerHTML = '';
        document.body.style.overflow = '';
    });

    afterEach(() => {
        resetModalSurfaces();
    });

    it('moves focus into the dialog when it opens', async () => {
        const wrapper = mount(Harness, { attachTo: document.body });

        await wrapper.find('#trigger').trigger('click');
        await flush();

        expect(openDialog()?.contains(document.activeElement)).toBe(true);

        wrapper.unmount();
    });

    it('locks background scroll while open and restores it on close', async () => {
        const wrapper = mount(Harness, { attachTo: document.body });

        expect(document.body.style.overflow).toBe('');

        await wrapper.find('#trigger').trigger('click');
        expect(document.body.style.overflow).toBe('hidden');
        expect(backgroundIsLocked()).toBe(true);

        await wrapper.setData({ open: false });
        expect(document.body.style.overflow).toBe('');
        expect(backgroundIsLocked()).toBe(false);

        wrapper.unmount();
    });

    it('marks the application root inert while open', async () => {
        const wrapper = mount(Harness, { attachTo: document.body });

        await wrapper.find('#trigger').trigger('click');

        // jsdom does not implement what `inert` *does* — that is a browser
        // behaviour. This asserts only that the attribute is applied and
        // removed, which is the part this codebase controls.
        expect(document.getElementById('app')?.hasAttribute('inert')).toBe(true);

        await wrapper.setData({ open: false });
        expect(document.getElementById('app')?.hasAttribute('inert')).toBe(false);

        wrapper.unmount();
    });

    it('closes on Escape', async () => {
        const wrapper = mount(Harness, { attachTo: document.body });

        await wrapper.find('#trigger').trigger('click');
        pressKeyInDialog('Escape');
        await flush();

        expect(openDialog()).toBeNull();

        wrapper.unmount();
    });

    it('wraps Tab from the last control back to the first', async () => {
        const wrapper = mount(Harness, { attachTo: document.body });

        await wrapper.find('#trigger').trigger('click');
        await flush();

        const controls = dialogControls();
        controls[controls.length - 1]?.focus();

        pressKeyInDialog('Tab');

        expect(document.activeElement).toBe(controls[0]);

        wrapper.unmount();
    });

    it('wraps Shift+Tab from the first control back to the last', async () => {
        const wrapper = mount(Harness, { attachTo: document.body });

        await wrapper.find('#trigger').trigger('click');
        await flush();

        const controls = dialogControls();
        controls[0]?.focus();

        pressKeyInDialog('Tab', true);

        expect(document.activeElement).toBe(controls[controls.length - 1]);

        wrapper.unmount();
    });

    it('returns focus to whatever opened it', async () => {
        const wrapper = mount(Harness, { attachTo: document.body });

        // Focused explicitly: jsdom does not focus a button on click the way a
        // browser does, and the behaviour under test is "focus goes back to
        // wherever it was", which needs it to have been somewhere.
        document.getElementById('trigger')?.focus();
        await wrapper.find('#trigger').trigger('click');
        await flush();

        await wrapper.setData({ open: false });

        expect(document.activeElement?.id).toBe('trigger');

        wrapper.unmount();
    });

    it('releases the background when unmounted while still open', async () => {
        // The stale-lock case: a route change tears the dialog down without
        // ever setting `open` back to false. Nothing would restore scrolling,
        // and the page would be silently frozen from then on.
        const wrapper = mount(Harness, { attachTo: document.body });

        await wrapper.find('#trigger').trigger('click');
        expect(document.body.style.overflow).toBe('hidden');

        wrapper.unmount();

        expect(document.body.style.overflow).toBe('');
        expect(backgroundIsLocked()).toBe(false);
    });

    it('keeps the page locked until the last of two stacked surfaces closes', async () => {
        // A confirmation raised from inside a drawer. Counting matters: without
        // it, closing the inner dialog would hand scrolling back to a page that
        // is still covered by the outer one.
        const Stacked = defineComponent({
            data() {
                return { outer: true, inner: true };
            },
            render() {
                return h('div', { id: 'app' }, [
                    h(AppModal, { open: this.outer, title: 'Outer' }),
                    h(AppModal, { open: this.inner, title: 'Inner' }),
                ]);
            },
        });

        const wrapper = mount(Stacked, { attachTo: document.body });

        expect(document.body.style.overflow).toBe('hidden');

        await wrapper.setData({ inner: false });
        expect(document.body.style.overflow).toBe('hidden');

        await wrapper.setData({ outer: false });
        expect(document.body.style.overflow).toBe('');

        wrapper.unmount();
    });
});
