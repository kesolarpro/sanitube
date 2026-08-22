/**
 * What the settings read model produces.
 *
 * There is **no value field on a secret**, and that absence is the whole
 * design. Not a mask, not a suffix, not a length — a masked secret is a secret
 * with the guessable part left in, and a length narrows a search.
 *
 * `configured` and `known` answer different questions. `configured: false`
 * means the variable is empty on this machine. `known: false` means a provider
 * is named that this installation has no code for — a configuration mistake
 * rather than a missing credential, and a different thing to fix.
 */

export interface SettingValue {
    variable: string;
    secret: boolean;
    configured: boolean;
    /** Present only when `secret` is false. Never sent for a credential. */
    value?: string;
}

export interface SettingsSection {
    key: string;
    selected: string;
    known: boolean;
    options: string[];
    settings: SettingValue[];
    secrets: SettingValue[];
    /**
     * Which connection test this section may run, or null for one that has
     * nothing to reach. The word comes from the server and is sent back
     * unchanged — the browser never composes a target, so the button cannot
     * become a request somebody else writes.
     */
    probe: string | null;
    complete: boolean;
}

/** What a connection test answers with. Never a URL, never a credential. */
export interface ProbeResult {
    status: string;
    checks: { name: string; outcome: string; detail: string | null }[];
}

export interface ApplicationSettings {
    name: string;
    environment: string;
    debug: boolean;
    locale: string;
    locales: string[];
    config_cached: boolean;
}

export interface SettingsOverview {
    application: ApplicationSettings;
    sections: SettingsSection[];
}
