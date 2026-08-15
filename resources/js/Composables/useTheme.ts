import { ref, watch } from 'vue';

export type ThemePreference = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'sanitube.theme';

/**
 * Light, dark, or whatever the operating system says.
 *
 * Three states rather than a boolean, because "follow the system" is a real
 * preference and a toggle cannot express it — a user on automatic night mode
 * who is forced to pick one gets the wrong theme for half the day.
 *
 * The preference is kept in `localStorage` and *not* on the server. It is a
 * device choice: the same person on a bright laptop and a dark phone wants
 * different answers, and a server-side setting would give them one.
 *
 * The class is applied by an inline script in the layout before first paint;
 * this composable only handles changes made after the page is running. Doing
 * it here alone would show a flash of the wrong theme on every load.
 */
export function useTheme() {
    const preference = ref<ThemePreference>(read());
    const media = window.matchMedia('(prefers-color-scheme: dark)');

    function read(): ThemePreference {
        const stored = window.localStorage.getItem(STORAGE_KEY);

        return stored === 'light' || stored === 'dark' || stored === 'system' ? stored : 'system';
    }

    function resolved(value: ThemePreference): 'light' | 'dark' {
        return value === 'system' ? (media.matches ? 'dark' : 'light') : value;
    }

    function apply(): void {
        document.documentElement.classList.toggle('dark', resolved(preference.value) === 'dark');
    }

    watch(preference, (value) => {
        window.localStorage.setItem(STORAGE_KEY, value);
        apply();
    });

    // Only while following the system: a user who chose dark explicitly does
    // not want their laptop's sunset schedule overriding them.
    media.addEventListener('change', () => {
        if (preference.value === 'system') {
            apply();
        }
    });

    return { preference, apply, resolved: () => resolved(preference.value) };
}
