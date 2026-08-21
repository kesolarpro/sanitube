import { describe, expect, it } from 'vitest';

/**
 * UI-A11Y-001. Every screen reaches its controls through the design system.
 *
 * `FormField` wires `for`/`id` and `aria-describedby`, and its own comment
 * records why it has to *provide* that wiring rather than offer it: sixty-one
 * of the platform's sixty-five fields never bound the slot props, and a label
 * pointing at nothing is silent — the screen looks right and a screen-reader
 * user hears "edit text, blank".
 *
 * Providing it fixed sixty-one screens and left one hole open. **The wiring is
 * picked up by the controls**, through `useField()`, so a screen that writes a
 * raw `<input>` inside a `FormField` gets a label pointing at an id no element
 * carries — exactly the failure the mechanism was changed to prevent, in the
 * one shape the change could not reach.
 *
 * Three of them were on the enrichment review screen: title, language and
 * genres, the fields where a reviewer decides what to do with what a language
 * model said. `modal-surface.spec` and `form-field.spec` both passed
 * throughout, because both test the primitives and neither asks whether a
 * screen used them. The ledger said as much — "design-system primitives do it;
 * not audited per screen" — and this is that audit, in the form that stays
 * true.
 *
 * Every exemption is a line with a sentence. A raw control is sometimes right;
 * an unexplained one never is.
 */

/**
 * Every screen's source, read through Vite rather than through `fs`.
 *
 * `import.meta.glob` resolves at build time and needs no Node types, which
 * keeps this file inside the same `vue-tsc` pass as the components it is about
 * — a test excluded from the type check is a test that drifts from them.
 */
const SCREENS = import.meta.glob('/resources/js/Pages/**/*.vue', {
    query: '?raw',
    import: 'default',
    eager: true,
}) as Record<string, string>;

/**
 * Raw controls that are deliberate, and why each one may be.
 *
 * Keyed by the path relative to `resources/js`, because that is what a reader
 * grepping for the file will type.
 */
const ALLOWED: Record<string, string> = {
    'Pages/Ingestion/Candidates/Index.vue':
        'Selection checkboxes in a table. Each carries its own aria-label — the row it selects, and "select all on this page" in the header — because a checkbox in a data grid has no visible label to point a FormField at.',
    'Pages/Ingestion/Import.vue':
        'A file input held off-screen and clicked by the visible button beside it. Marked aria-hidden and tabindex="-1" so a screen reader meets one control rather than two, the second unnamed.',
    'Pages/Assets/Upload.vue':
        'The same off-screen file input, for the same reason. sr-only hides a control visually and leaves it in the accessibility tree, which is the trap.',
};

/** The elements a design-system component exists for. */
const RAW = /<(input|select|textarea)\b/g;

function key(path: string): string {
    return path.replace('/resources/js/', '');
}

/**
 * The template, without the comments.
 *
 * `Upload.vue`'s own docblock says the words `<input type="file">` while
 * explaining why the screen exists, and a scan that read comments would report
 * the file that documents the problem as the file that has it.
 */
function template(source: string): string {
    return source
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/.*$/gm, '$1');
}

function rawControlsIn(source: string): RegExpMatchArray | null {
    return template(source).match(RAW);
}

describe('screens use the design system', () => {
    const screens = Object.entries(SCREENS);

    it('reads the screens, so a scan that found nothing cannot pass', () => {
        expect(screens.length).toBeGreaterThan(20);
    });

    it('reaches every form control through a primitive, or says why not', () => {
        const offenders: string[] = [];

        for (const [path, source] of screens) {
            const matches = rawControlsIn(source);

            if (matches === null || key(path) in ALLOWED) {
                continue;
            }

            offenders.push(`${key(path)} (${matches.join(', ')})`);
        }

        expect(
            offenders,
            'A raw control does not pick up the label FormField provides, so the label points at nothing '
                + 'and announces nothing. Use TextInput/SelectInput/TextareaInput, or name the file in '
                + 'ALLOWED with the reason.',
        ).toEqual([]);
    });

    it('names no exemption that has stopped being one', () => {
        for (const [exempted, reason] of Object.entries(ALLOWED)) {
            const source: string | undefined = SCREENS[`/resources/js/${exempted}`];

            // Asserted rather than asserted-away: an exemption naming a screen
            // that has been deleted or moved is an exemption for nothing, and
            // reading it as an empty string would let it sit there for ever.
            expect(source, `${exempted} is exempted and no longer exists.`).toBeTypeOf('string');

            expect(
                rawControlsIn(source ?? ''),
                `${exempted} is exempted and has no raw control. An exemption for nothing hides the next one.`,
            ).not.toBeNull();

            expect(reason.length, `${exempted} is exempted with something that is not a reason.`).toBeGreaterThan(40);
        }
    });
});
