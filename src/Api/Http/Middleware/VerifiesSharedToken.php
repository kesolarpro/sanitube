<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared-token access, with the safe default that matters: **no token
 * configured means the routes do not exist**.
 *
 * Returning 404 rather than 401 when nothing is configured is deliberate. A
 * fresh install should not advertise the existence of an internal API at all,
 * and an unconfigured deployment should fail closed rather than fall back to
 * something permissive.
 *
 * This is a stopgap until the Identity module brings real authentication. It
 * is not an authorisation model: every holder of the token sees everything the
 * routes expose, which is why those routes are read-only and why asset paths
 * never appear in them.
 */
abstract class VerifiesSharedToken
{
    /**
     * Config key holding the expected token.
     */
    abstract protected function tokenConfigKey(): string;

    /**
     * Config key holding the header name to read it from.
     */
    abstract protected function headerConfigKey(): string;

    abstract protected function defaultHeader(): string;

    public function handle(Request $request, Closure $next): Response
    {
        $expected = config($this->tokenConfigKey());

        if (! is_string($expected) || $expected === '') {
            abort(404);
        }

        $header = (string) config($this->headerConfigKey(), $this->defaultHeader());
        $provided = $request->header($header);

        // hash_equals: comparing tokens with === leaks their prefix through
        // timing, one character at a time.
        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid token.');
        }

        return $next($request);
    }
}
