import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import TextareaInput from '@/Components/Ui/TextareaInput.vue';

/**
 * A label has to point at the control it labels.
 *
 * This exists because it did not. `FormField` generated an `id` and offered it
 * as a slot prop, and **sixty-one of the platform's sixty-five fields never
 * bound it** — every one of them rendering a `<label for>` aimed at nothing,
 * with the control carrying no id, no `aria-describedby` and no
 * `aria-invalid`.
 *
 * That failure is silent in a way most are not. The screen looks correct, the
 * error message is visible, and only somebody using a screen reader finds out —
 * they tab into the field and hear "edit text, blank".
 *
 * The fix was to stop asking authors to remember: the field provides its
 * wiring and the controls take it. These tests assert the *behaviour that
 * results*, so writing a new field the ordinary way stays correct and a
 * regression in the mechanism shows up here rather than in an audit a year
 * later.
 */

function field(control: unknown, props: Record<string, unknown> = {}) {
    return mount(
        defineComponent({
            render: () => h(FormField, { label: 'Release title', ...props }, () => h(control as never)),
        }),
    );
}

describe('form field wiring', () => {
    it.each([
        ['text input', TextInput, 'input'],
        ['select', SelectInput, 'select'],
        ['textarea', TextareaInput, 'textarea'],
    ])('associates the label with a %s the ordinary way, with nothing bound by hand', (_name, control, selector) => {
        const wrapper = field(control);

        const label = wrapper.find('label');
        const input = wrapper.find(selector);

        const id = input.attributes('id');

        expect(id, 'the control was rendered without an id, so no label can point at it').toBeTruthy();
        expect(label.attributes('for')).toBe(id);
    });

    it('points aria-describedby at the error when there is one', () => {
        const wrapper = field(TextInput, { error: 'A title is required.' });

        const described = wrapper.find('input').attributes('aria-describedby');

        expect(described, 'the error is visible but unannounced').toBeTruthy();
        expect(wrapper.find(`#${described}`).text()).toBe('A title is required.');

        // Colour is not the only signal. A red border is an error some users
        // never see.
        expect(wrapper.find('input').attributes('aria-invalid')).toBe('true');
    });

    it('describes nothing when there is nothing to describe', () => {
        // Pointing at an element that does not exist makes some screen readers
        // announce nothing at all — worse than staying quiet.
        const wrapper = field(TextInput);

        expect(wrapper.find('input').attributes('aria-describedby')).toBeUndefined();
        expect(wrapper.find('input').attributes('aria-invalid')).toBeUndefined();
    });

    it('lets a screen place its own id when it genuinely needs to', () => {
        const wrapper = field(TextInput);

        // The injected wiring is a default, not a seizure: one label describing
        // two controls still has to be expressible.
        const standalone = mount(TextInput, { props: { id: 'chosen-by-hand' } });

        expect(standalone.find('input').attributes('id')).toBe('chosen-by-hand');
        expect(wrapper.find('input').attributes('id')).not.toBe('chosen-by-hand');
    });

    it('works outside a field, because a toolbar search box is not a mistake', () => {
        const standalone = mount(TextInput);

        expect(standalone.find('input').attributes('id')).toBeUndefined();
        expect(standalone.find('input').attributes('aria-invalid')).toBeUndefined();
    });
});
