import { inject, provide, type InjectionKey, type Ref } from 'vue';

/**
 * What a field tells the control inside it.
 *
 * `FormField` has always generated an `id`, an `aria-describedby` and an
 * invalid flag, and exposed them as slot props. **Sixty-one of the platform's
 * sixty-five fields never bound them** — which is not sixty-one careless
 * authors, it is a mechanism that was opt-in for something that must never be
 * forgotten. A `<label for>` pointing at nothing is silent: the screen looks
 * right, and a screen-reader user tabbing into it hears "edit text, blank".
 *
 * So the wiring travels by injection instead. A control inside a field picks
 * it up whether or not the author thought about it, and a control used outside
 * one keeps working exactly as before.
 *
 * An explicitly passed prop still wins. The rare screen that genuinely needs
 * its own id — one label describing two controls, say — can still say so.
 */
export interface FieldDescriptor {
    id: string;
    describedBy: Ref<string | undefined>;
    invalid: Ref<boolean>;
}

export const fieldKey: InjectionKey<FieldDescriptor> = Symbol('sanitube.field');

export function provideField(descriptor: FieldDescriptor): void {
    provide(fieldKey, descriptor);
}

/**
 * The field this control is inside, if it is inside one.
 *
 * Returns undefined rather than throwing: a control outside a `FormField` is
 * an ordinary thing — a search box in a toolbar — not a mistake.
 */
export function useField(): FieldDescriptor | undefined {
    return inject(fieldKey, undefined);
}
