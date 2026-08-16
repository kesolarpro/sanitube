<?php

declare(strict_types=1);

namespace SaniTube\Audit\Support;

use SaniTube\Ui\Queries\QueueQuery;

/**
 * The gate every piece of free-form context passes through.
 *
 * An audit log is the one table that is read by people looking for trouble,
 * copied into every backup, and kept long after the rows it describes are
 * gone. A secret that reaches it does not reach it once.
 *
 * The obvious design — "callers know not to pass secrets" — is not a
 * boundary, it is a hope about every call site that will ever be written. So
 * the context array is filtered structurally, and the filter is deliberately
 * blunt: **it is easier to defend a rule that occasionally discards something
 * useful than one that occasionally keeps something dangerous.**
 *
 * Four rules, in the order they fire:
 *
 *   1. **A denylisted key is redacted whatever its value.** `password`,
 *      `token`, `secret`, `credential`, `signature`, `authorization` and
 *      friends, matched as substrings so `db_password` and `reset_token` are
 *      caught too. Note what is *not* on the list: `idempotency_key` and
 *      `object_key` are derived from public data and are exactly what an
 *      operator needs, so the list names the dangerous words rather than the
 *      word "key".
 *
 *   2. **No URL is ever stored.** Not a signed one, not an unsigned one. A
 *      signed URL is a bearer credential and a private object URL is a
 *      location; neither belongs in a log, and no audit line has ever needed
 *      one. Detecting "signed" specifically would mean keeping up with every
 *      provider's signature parameter, which is a race nobody wins.
 *
 *   3. **No credential-shaped string.** A `Bearer …` header value or a PEM
 *      block, wherever it appears and whatever it is called.
 *
 *   4. **Nothing long.** A value over {@see self::MAX_VALUE_LENGTH} characters
 *      is discarded rather than truncated, because a truncated secret is
 *      still a secret with its first 200 characters intact. This is also what
 *      stops an audited operation from filling the log with whatever it was
 *      given.
 *
 * And two bounds that are about the log rather than about secrets: at most
 * {@see self::MAX_KEYS} keys, and one level of nesting. An audit context is a
 * handful of short facts. Anything shaped like a document is a payload, and
 * payloads are what {@see QueueQuery} already refuses to
 * publish for the same reason.
 *
 * **What this cannot do.** A short secret under an innocuous key — a six-digit
 * code called `value` — passes every rule. No filter can tell that apart from
 * a legitimate short value. The rules above remove the *shapes* that leak in
 * practice; they do not remove the need to think at the call site.
 */
final readonly class Redaction
{
    /** What replaces a value that must not be kept. */
    public const PLACEHOLDER = '[redacted]';

    /** Longer than this and the value is discarded, never truncated. */
    public const MAX_VALUE_LENGTH = 200;

    /** An audit context is a handful of facts, not a document. */
    public const MAX_KEYS = 20;

    /**
     * Words that make a value unkeepable regardless of what it holds.
     *
     * Matched as substrings, case-insensitively. The absence of a bare "key"
     * is deliberate — see the class comment.
     *
     * @var list<string>
     */
    private const DENIED_KEY_FRAGMENTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'credential',
        'authorization',
        'authorisation',
        'signature',
        'api_key',
        'apikey',
        'access_key',
        'private_key',
        'bearer',
        'session',
        'cookie',
    ];

    /**
     * Filter a context array into something safe to keep forever.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<string, scalar|null|array<string, scalar|null>>
     */
    public function scrub(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if (count($safe) >= self::MAX_KEYS) {
                break;
            }

            $name = $this->key($key);

            if ($name === null) {
                continue;
            }

            if ($this->isDeniedKey($name)) {
                $safe[$name] = self::PLACEHOLDER;

                continue;
            }

            if (is_array($value)) {
                // One level. A nested array is already a strange thing for an
                // audit line to carry; two would be a payload.
                $safe[$name] = $this->flat($value);

                continue;
            }

            $safe[$name] = $this->value($value);
        }

        return $safe;
    }

    /**
     * Whether a string would survive on its own.
     *
     * Exposed so a call site can ask before it builds a context, and so the
     * rules have one place to be tested against.
     */
    public function isKeepable(string $value): bool
    {
        if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            return false;
        }

        // Rule 2: no URL, signed or otherwise.
        if (str_contains($value, '://')) {
            return false;
        }

        // Rule 3: credential shapes, wherever they turn up.
        if (preg_match('/^\s*bearer\s+\S/i', $value) === 1) {
            return false;
        }

        return ! str_contains($value, '-----BEGIN');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, scalar|null>
     */
    private function flat(array $value): array
    {
        $flat = [];

        foreach ($value as $key => $item) {
            if (count($flat) >= self::MAX_KEYS) {
                break;
            }

            $name = $this->key($key);

            if ($name === null) {
                continue;
            }

            if ($this->isDeniedKey($name)) {
                $flat[$name] = self::PLACEHOLDER;

                continue;
            }

            // A second level of nesting is not flattened, it is refused.
            // Flattening would invent key names that never existed.
            $flat[$name] = is_array($item) ? self::PLACEHOLDER : $this->value($item);
        }

        return $flat;
    }

    private function value(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            // An object, a resource, a closure. Recording its type says the
            // call site passed something it should not have, without keeping
            // whatever that was.
            return self::PLACEHOLDER;
        }

        $string = (string) $value;

        return $this->isKeepable($string) ? $string : self::PLACEHOLDER;
    }

    /**
     * A key that is safe to store and to render.
     *
     * Numeric keys are dropped rather than stringified: a list has no business
     * in an audit context, and `0 => 'DRAFT'` reads as a fact with no name.
     */
    private function key(mixed $key): ?string
    {
        if (! is_string($key) || $key === '') {
            return null;
        }

        return preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) === 1 ? $key : null;
    }

    private function isDeniedKey(string $key): bool
    {
        foreach (self::DENIED_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
