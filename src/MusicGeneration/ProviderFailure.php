<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration;

use Illuminate\Http\Client\RequestException;
use SaniTube\AI\Providers\HttpAiProvider;
use SaniTube\MusicGeneration\Exceptions\GenerationException;
use SaniTube\Storage\CredentialRedactor;
use Throwable;

/**
 * The only thing allowed to write `failure_reason`.
 *
 * A generation's failure reason is rendered on the Studio screen and returned
 * by the API, so it is a string that reaches a browser. Before this class it
 * was whatever the provider or the HTTP client happened to say — and both of
 * those routinely quote the request back. Guzzle's exception message carries
 * the full request URI; Laravel's `RequestException` appends the response body.
 * On a provider authenticated by a query-string token, that message *is* the
 * credential.
 *
 * So text is sorted by who wrote it, and the three answers get three different
 * treatments:
 *
 * - **The platform's own words** — a `GenerationException`, or a literal in one
 *   of these services — are carried unchanged. We wrote them; they say only
 *   what we chose to say.
 * - **A vendor's explanation** ("your prompt was rejected by our content
 *   policy") is worth showing, so it is kept — but redacted against every
 *   configured credential, stripped of anything URL-shaped, flattened to one
 *   line, bounded, and prefixed so a reader knows the vendor is speaking and
 *   not the platform.
 * - **Any other throwable** is client internals rather than an explanation.
 *   Nothing of the message survives; the status code does, because that is the
 *   part an operator can act on — 401 is a key, 429 is a rate limit, 5xx is
 *   theirs.
 *
 * The same reasoning {@see HttpAiProvider::describeFailure()}
 * applies to completions, and for the same reason: redaction can mask the
 * credential this installation is configured with, but it cannot mask a prompt
 * quoted back, and the prompt is catalogue data.
 */
final readonly class ProviderFailure
{
    /**
     * Long enough for a real content-policy explanation, short enough that a
     * provider echoing the whole request cannot fill the column with it.
     */
    public const LIMIT = 300;

    /**
     * What a thrown exception is allowed to say.
     */
    public static function fromThrowable(string $provider, Throwable $exception): string
    {
        // Ours. The message was written in this repository and is already the
        // sentence we intend somebody to read.
        if ($exception instanceof GenerationException) {
            return $exception->getMessage();
        }

        if ($exception instanceof RequestException) {
            return sprintf('The [%s] provider answered %d.', $provider, $exception->response->status());
        }

        // Deliberately says nothing about what went wrong. A transport
        // exception describes the request, and the request is the thing that
        // must not be described.
        return sprintf('The [%s] provider could not be reached.', $provider);
    }

    /**
     * What a vendor's own explanation is allowed to say.
     *
     * @param  string|null  $text  the provider's words, straight off the wire
     * @param  string  $fallback  the platform's sentence for when there are none.
     *                            Not passed through the sanitiser: it is ours.
     */
    public static function fromProvider(string $provider, ?string $text, string $fallback): string
    {
        $clean = self::sanitise($text);

        if ($clean === null) {
            return $fallback;
        }

        // Attributed, because the two are not the same claim. "Rejected for
        // explicit content" from the platform is a policy decision somebody can
        // appeal to us; from a vendor it is a report of what they did.
        return sprintf('The [%s] provider reported: %s', $provider, $clean);
    }

    /**
     * The same treatment, applied again where the column is read.
     *
     * Defence in depth, and one specific case: every row written after this
     * ticket went through {@see fromThrowable()} or {@see fromProvider()} and
     * passes through here unchanged, but rows written *before* it hold whatever
     * the provider or the HTTP client said. A migration cannot clean those —
     * the damage, if any, is already in the column — so the read boundary
     * refuses to render an address or an unbounded blob regardless of how it
     * got there.
     *
     * No attribution is added. Text arriving here has either been attributed
     * already or was never a vendor's to begin with.
     */
    public static function forDisplay(?string $stored): ?string
    {
        return self::sanitise($stored);
    }

    private static function sanitise(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $clean = CredentialRedactor::fromDiskConfiguration()->redact($text) ?? '';

        // Anything URL-shaped goes, whether or not it holds a recognised
        // credential. A signed URL's token is not in any config file, so
        // redaction cannot know it -- the only safe rule is that a failure
        // reason never contains an address.
        $clean = (string) preg_replace('#\b[a-zA-Z][a-zA-Z0-9+.-]*://\S+#', CredentialRedactor::MASK, $clean);

        // One line. A stack trace pasted into a column is both a leak and
        // unreadable on the screen that renders it.
        $clean = (string) preg_replace('/\s+/u', ' ', $clean);
        $clean = trim($clean);

        if ($clean === '' || $clean === CredentialRedactor::MASK) {
            return null;
        }

        return mb_strimwidth($clean, 0, self::LIMIT, '…');
    }
}
