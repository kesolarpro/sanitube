import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/Types/inertia';

/**
 * Translating in the browser.
 *
 * The lines come from the server on every page, already resolved for the
 * current locale — so there is no second HTTP request for a language pack, no
 * duplicate copy of the strings in the bundle, and no possibility of the
 * interface being in one language while the server thinks it is in another.
 *
 * A missing key returns the key itself rather than an empty string. Blank text
 * looks like a layout bug and gets ignored; `catalog.tracks.title` on screen
 * is unmistakable and gets fixed. The translation-completeness test is what
 * stops one reaching production, but the fallback still has to be honest.
 */
export function trans(key: string, replacements: Record<string, string | number> = {}): string {
    const page = usePage<SharedProps>();
    const line = page.props.translations?.[key];

    if (typeof line !== 'string') {
        return key;
    }

    return Object.entries(replacements).reduce(
        (text, [token, value]) => text.replaceAll(`:${token}`, String(value)),
        line,
    );
}

/**
 * A count-aware line, using Laravel's `one|other` pluralisation.
 *
 * Deliberately minimal: the six locales SaniTube ships all use a two-form
 * plural, so a full CLDR plural-rule engine in the bundle would be weight
 * nothing here needs.
 */
export function transChoice(key: string, count: number, replacements: Record<string, string | number> = {}): string {
    const line = trans(key, { ...replacements, count });
    const forms = line.split('|');

    if (forms.length < 2) {
        return line;
    }

    return (count === 1 ? forms[0] : forms[1]) ?? line;
}
