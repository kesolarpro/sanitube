import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Import from '@/Pages/Ingestion/Import.vue';
import { csrfToken, refusalCode, refusalFrom } from '@/Support/request';
import type { ImportCapability } from '@/Types/ingestion';
import { sharedProps } from './support/inertia';

vi.mock('@inertiajs/vue3', () => import('./support/inertia'));

/**
 * UPL-005 — what a refused upload is called.
 *
 * The production failure this closes had two halves, and the second is the one
 * that made the first take weeks to find. The layout stopped publishing the
 * CSRF token, so every relayed upload was refused with 419 before a controller
 * ran; and the screen, reading only a `code` key that a framework refusal does
 * not carry, reported all of them as *the file could not be deposited*.
 *
 * The server had said `CSRF token mismatch.` The interface threw it away and
 * substituted a sentence that named nothing, so the one piece of evidence that
 * would have pointed straight at the cause never reached the person looking at
 * it, and nothing was written to the log either — a rendered 419 is not a
 * reported exception.
 *
 * These hold the reading itself. The token being published at all is a
 * server-side contract and is held by `CsrfTokenIsAvailableTest`.
 */

function capability(overrides: Partial<ImportCapability> = {}): ImportCapability {
    return {
        direct: false,
        accepted_types: ['audio/mpeg'],
        maximum_bytes: 2 * 1024 * 1024 * 1024,
        effective_maximum_bytes: 64 * 1024 * 1024,
        limited_by_php: true,
        php_post_limit_bytes: 64 * 1024 * 1024,
        per_request: 50,
        concurrency: 3,
        max_batch: 500,
        waiting: { available: true, files: [] },
        ...overrides,
    } as ImportCapability;
}

describe('reading a refusal', () => {
    it('names an expired session instead of failing generically', () => {
        // Laravel's own 419. A sentence, in one language, with no `code` — and
        // until UPL-005 this exact body became DEPOSIT_FAILED.
        expect(refusalCode(419, '{"message": "CSRF token mismatch."}', 'DEPOSIT_FAILED')).toBe('SESSION_EXPIRED');
    });

    it('names the host limit for a body refused before PHP saw it', () => {
        // nginx and Apache refuse an oversized body themselves, with an HTML
        // error page. There is no JSON to read a code out of.
        expect(refusalCode(413, '<html><body><h1>413 Request Entity Too Large</h1></body></html>', 'DEPOSIT_FAILED')).toBe(
            'HOST_UPLOAD_LIMIT',
        );
    });

    it('keeps the code a refusal named for itself', () => {
        // The application's own refusals are more specific than any status:
        // both of these are 422 and they are not the same thing to read.
        expect(refusalCode(422, '{"code": "MEDIA_TYPE_NOT_ACCEPTED"}', 'DEPOSIT_FAILED')).toBe('MEDIA_TYPE_NOT_ACCEPTED');
        expect(refusalCode(422, '{"code": "FILE_TOO_LARGE"}', 'DEPOSIT_FAILED')).toBe('FILE_TOO_LARGE');
    });

    it('prefers the named code even when the status is one it knows', () => {
        // A 413 from our own form request carries HOST_UPLOAD_LIMIT *and* the
        // host's number. The body wins, so nothing downstream loses the number.
        expect(refusalCode(413, '{"code": "HOST_UPLOAD_LIMIT", "limit_bytes": 2097152}', 'DEPOSIT_FAILED')).toBe(
            'HOST_UPLOAD_LIMIT',
        );
    });

    it('lets a code the server named beat the name the status would have got', () => {
        // The ordering, stated as a rule rather than left to be incidental.
        // Every mapped status today either carries no code or carries the same
        // one, so nothing observable turns on it *yet* — which is exactly when
        // an ordering silently flips. A code the server wrote is always more
        // specific than the status it happened to travel with.
        expect(refusalCode(413, '{"code": "FILE_TOO_LARGE"}', 'DEPOSIT_FAILED')).toBe('FILE_TOO_LARGE');
        expect(refusalCode(403, '{"code": "BACKGROUND_WORK_PAUSED"}', 'DEPOSIT_FAILED')).toBe('BACKGROUND_WORK_PAUSED');
        expect(refusalCode(419, '{"code": "STORAGE_UNAVAILABLE"}', 'DEPOSIT_FAILED')).toBe('STORAGE_UNAVAILABLE');
    });

    it('keeps a named code on a status it would otherwise have mapped', () => {
        // Not hypothetical: the relay answers 503 with STORAGE_UNAVAILABLE —
        // "storage did not answer, nothing was lost, try again" — and reading
        // the status first would flatten it into SERVER_ERROR and lose the one
        // sentence that says the file is still fine.
        expect(refusalCode(503, '{"code": "STORAGE_UNAVAILABLE"}', 'DEPOSIT_FAILED')).toBe('STORAGE_UNAVAILABLE');
    });

    it('separates being signed out from not being allowed', () => {
        expect(refusalCode(401, '', 'DEPOSIT_FAILED')).toBe('NOT_SIGNED_IN');
        expect(refusalCode(403, '', 'DEPOSIT_FAILED')).toBe('NOT_PERMITTED');
    });

    it('says a server could not finish rather than blaming the file', () => {
        for (const status of [500, 502, 503, 504]) {
            expect(refusalCode(status, 'Server Error', 'DEPOSIT_FAILED')).toBe('SERVER_ERROR');
        }
    });

    it('falls back to the callers own word, and each screen has its own', () => {
        // A status nothing maps and a body naming nothing. This is the only
        // case the fallback is for; it used to be every case.
        expect(refusalCode(418, '', 'DEPOSIT_FAILED')).toBe('DEPOSIT_FAILED');
        expect(refusalCode(418, '', 'UPLOAD_NOT_ACCEPTABLE')).toBe('UPLOAD_NOT_ACCEPTABLE');
        expect(refusalCode(400, 'not json at all', 'DEPOSIT_FAILED')).toBe('DEPOSIT_FAILED');
        expect(refusalCode(422, '{"code": 42}', 'DEPOSIT_FAILED')).toBe('DEPOSIT_FAILED');
        expect(refusalCode(422, '{"code": ""}', 'DEPOSIT_FAILED')).toBe('DEPOSIT_FAILED');
    });

    it('reads a fetch response the same way', async () => {
        const response = {
            status: 419,
            text: () => Promise.resolve('{"message": "CSRF token mismatch."}'),
        } as Response;

        await expect(refusalFrom(response, 'UPLOAD_NOT_ACCEPTABLE')).resolves.toBe('SESSION_EXPIRED');
    });

    it('still answers when the body cannot be read at all', async () => {
        const response = {
            status: 500,
            text: () => Promise.reject(new Error('stream already consumed')),
        } as unknown as Response;

        await expect(refusalFrom(response, 'UPLOAD_NOT_ACCEPTABLE')).resolves.toBe('SERVER_ERROR');
    });
});

describe('the token a hand-built request carries', () => {
    it('is the one the layout published', () => {
        document.head.innerHTML = '<meta name="csrf-token" content="a-real-token">';

        expect(csrfToken()).toBe('a-real-token');
    });

    it('is empty when the layout published none, rather than invented', () => {
        // The production state. An empty header gets the request refused, which
        // is right: the fix is to publish the token, never to stop sending it.
        document.head.innerHTML = '<meta name="viewport" content="width=device-width">';

        expect(csrfToken()).toBe('');
    });
});

/** Enough of XMLHttpRequest for the relayed deposit path, and nothing more. */
class RecordedUpload {
    static sent: RecordedUpload[] = [];

    static status = 419;
    static body = '{"message": "CSRF token mismatch."}';

    readonly headers: Record<string, string> = {};
    readonly upload = { addEventListener: (): void => undefined };

    status = 0;
    responseText = '';

    private listeners = new Map<string, () => void>();

    open(): void {
        // The URL is asserted elsewhere; this class exists for the headers.
    }

    setRequestHeader(name: string, value: string): void {
        this.headers[name] = value;
    }

    addEventListener(event: string, handler: () => void): void {
        this.listeners.set(event, handler);
    }

    send(): void {
        RecordedUpload.sent.push(this);

        this.status = RecordedUpload.status;
        this.responseText = RecordedUpload.body;

        this.listeners.get('load')?.();
    }

    abort(): void {
        this.listeners.get('abort')?.();
    }
}

describe('the import screen, refused', () => {
    beforeEach(() => {
        sharedProps.translations = {};
        document.head.innerHTML = '<meta name="csrf-token" content="a-real-token">';

        RecordedUpload.sent = [];
        RecordedUpload.status = 419;
        RecordedUpload.body = '{"message": "CSRF token mismatch."}';

        vi.stubGlobal('XMLHttpRequest', RecordedUpload);
    });

    /**
     * Pick one file, deposit it, and give back what the screen ended up saying.
     *
     * The rendered text rather than a component internal: with no translations
     * loaded `trans()` returns the key, so what comes back is the line the
     * person would have read — `ui.import.refusal.<CODE>`.
     */
    async function deposit(): Promise<{ said: string; header: string | undefined }> {
        const wrapper = mount(Import, { props: { capability: capability() } });

        const file = new File(['x'.repeat(64)], 'Armure de Lumière.mp3', { type: 'audio/mpeg' });
        const input = wrapper.find('input[type="file"]');

        Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
        await input.trigger('change');

        const deposited = wrapper.findAll('button').find((button) => button.text() === 'ui.import.deposit');

        expect(deposited).toBeDefined();
        await deposited!.trigger('click');
        await settle(wrapper);

        return {
            said: wrapper.text(),
            header: RecordedUpload.sent[0]?.headers['X-CSRF-TOKEN'],
        };
    }

    /** The deposit awaits a promise chain; one tick is not enough to settle it. */
    async function settle(wrapper: { vm: { $nextTick(): Promise<void> } }): Promise<void> {
        for (let round = 0; round < 5; round += 1) {
            await new Promise((resolve) => setTimeout(resolve));
            await wrapper.vm.$nextTick();
        }
    }

    it('sends the published token and reports a 419 as an expired session', async () => {
        const { said, header } = await deposit();

        // The half that broke production: this header was the empty string.
        expect(header).toBe('a-real-token');

        // The half that hid it. `ui.import.refusal.SESSION_EXPIRED` tells the
        // person to reload the page, which is the action that fixes it.
        expect(said).toContain('ui.import.refusal.SESSION_EXPIRED');
        expect(said).not.toContain('ui.import.refusal.DEPOSIT_FAILED');
    });

    it('reports a body the web server threw away as the host limit', async () => {
        RecordedUpload.status = 413;
        RecordedUpload.body = '<html><head><title>413 Request Entity Too Large</title></head></html>';

        expect((await deposit()).said).toContain('ui.import.refusal.HOST_UPLOAD_LIMIT');
    });

    it('still reports a refusal the server named for itself', async () => {
        RecordedUpload.status = 422;
        RecordedUpload.body = '{"code": "MEDIA_TYPE_NOT_ACCEPTED"}';

        expect((await deposit()).said).toContain('ui.import.refusal.MEDIA_TYPE_NOT_ACCEPTED');
    });
});
