/**
 * Formatting values the way a music catalogue reads them.
 *
 * Every function here takes the *real* value or null. None of them invents a
 * placeholder number: a missing duration renders as an em dash, because
 * "0:00" is a duration somebody measured and null is not.
 */

/** A duration in milliseconds as `m:ss`, or `h:mm:ss` past an hour. */
export function duration(ms: number | null | undefined): string {
    if (ms === null || ms === undefined) {
        return '—';
    }

    const total = Math.round(ms / 1000);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const seconds = total % 60;

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    }

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

/** Bytes in the unit a person would say out loud. */
export function bytes(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = value;
    let unit = 0;

    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }

    return `${unit === 0 ? size : size.toFixed(1)} ${units[unit]}`;
}

/** A whole number, grouped for the current locale. */
export function count(value: number | null | undefined, locale: string): string {
    if (value === null || value === undefined) {
        return '—';
    }

    return new Intl.NumberFormat(locale).format(value);
}

/** An ISO timestamp as a short local date. */
export function date(iso: string | null | undefined, locale: string): string {
    if (!iso) {
        return '—';
    }

    const parsed = new Date(iso);

    return Number.isNaN(parsed.getTime())
        ? '—'
        : new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(parsed);
}

/** An ISO timestamp as a date and time. */
export function dateTime(iso: string | null | undefined, locale: string): string {
    if (!iso) {
        return '—';
    }

    const parsed = new Date(iso);

    return Number.isNaN(parsed.getTime())
        ? '—'
        : new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(parsed);
}

/** A sample rate in kHz, the way a mastering engineer says it. */
export function sampleRate(hz: number | null | undefined): string {
    return hz === null || hz === undefined ? '—' : `${(hz / 1000).toFixed(1)} kHz`;
}

/** A bitrate in kbps. */
export function bitrate(bps: number | null | undefined): string {
    return bps === null || bps === undefined ? '—' : `${Math.round(bps / 1000)} kbps`;
}
