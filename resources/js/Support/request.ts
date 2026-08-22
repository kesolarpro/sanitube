/**
 * The two things a hand-built request needs, in one place.
 *
 * Most of the interface never comes here: an Inertia visit is an axios call,
 * axios signs it from the `XSRF-TOKEN` cookie, and a refusal arrives as
 * validation errors on the page. The screens that upload cannot use Inertia —
 * `router.post` reports no progress and cannot be aborted mid-transfer — so
 * they build an `XMLHttpRequest` or a `fetch` themselves, and everything the
 * framework was doing for them they have to do here instead.
 *
 * Both of those things had gone wrong at once, which is why UPL-005 exists.
 */

/**
 * The token the session expects a write to carry.
 *
 * Read from the layout, which is the only place it is published. Three screens
 * used to each keep their own copy of this line; when the meta tag itself went
 * missing from `app.blade.php`, all three quietly started sending an empty
 * header and every upload died with a 419 before reaching a controller. One
 * copy of the line does not stop the tag being deleted — `CsrfTokenIsAvailable`
 * does that — but it does mean there is one place to look.
 *
 * An empty string when the tag is absent, deliberately: sending no token at all
 * gets the request refused, which is correct. Guessing one, or skipping the
 * header so some other path is taken, would not be.
 */
export function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * What the interface calls a refusal that carried no name of its own.
 *
 * A status this map does not know is not translated into a guess; it falls
 * through to the caller's own last resort.
 */
const NAMED_BY_STATUS: Record<number, string> = {
    // Signed out, or never signed in. Answered as JSON rather than as a
    // redirect because these requests ask for JSON.
    401: 'NOT_SIGNED_IN',
    // Signed in, and this account may not do this.
    403: 'NOT_PERMITTED',
    // The body was refused for its size. Our own form requests answer 413 with
    // `HOST_UPLOAD_LIMIT` in the body; a web server that refuses the body
    // before PHP sees it answers 413 with an HTML error page and no code at
    // all, and that is the case this line covers.
    413: 'HOST_UPLOAD_LIMIT',
    // Laravel's CSRF refusal. The body is `{"message": "CSRF token
    // mismatch."}` — a sentence, in one language, with no code — so a reader
    // that only looked for `code` learned nothing from the one response that
    // said exactly what was wrong.
    419: 'SESSION_EXPIRED',
};

/**
 * The stable name for a request the server refused.
 *
 * **The body is asked first.** Every refusal this application writes itself
 * carries a `code`, and that code is more specific than any status could be:
 * `MEDIA_TYPE_NOT_ACCEPTED` and `FILE_TOO_LARGE` are both 422 and are not the
 * same thing to the person reading them.
 *
 * **The status is asked second**, and only for the refusals this application
 * never writes: the framework's, the web server's, PHP's. Those arrive with a
 * sentence or with HTML, and until UPL-005 every one of them was flattened
 * into one code meaning "something went wrong" — which is how a production
 * outage with a precise cause was reported for weeks as *the file could not be
 * deposited*.
 *
 * **The caller's fallback is last**, because "deposited" and "uploaded" are
 * different words on different screens and neither should be hardcoded here.
 */
export function refusalCode(status: number, body: string, fallback: string): string {
    const named = codeIn(body);

    if (named !== null) {
        return named;
    }

    const byStatus = NAMED_BY_STATUS[status];

    if (byStatus !== undefined) {
        return byStatus;
    }

    // Anything the server could not finish. One code rather than five: there is
    // nothing a person at a browser does differently for a 500 than for a 503,
    // and pretending otherwise would put the operator's problem in their words.
    if (status >= 500 && status <= 599) {
        return 'SERVER_ERROR';
    }

    return fallback;
}

/** The same reading, for a `fetch` response whose body has not been read yet. */
export async function refusalFrom(response: Response, fallback: string): Promise<string> {
    let body = '';

    try {
        body = await response.text();
    } catch {
        // A body that cannot be read leaves the status, which is enough.
    }

    return refusalCode(response.status, body, fallback);
}

/** The `code` a JSON refusal named, or null when there is not one to read. */
function codeIn(body: string): string | null {
    try {
        const parsed = JSON.parse(body) as { code?: unknown };

        return typeof parsed.code === 'string' && parsed.code !== '' ? parsed.code : null;
    } catch {
        // Not JSON at all — an HTML error page from the web server, or an empty
        // body. Neither says anything the status has not already said.
        return null;
    }
}
