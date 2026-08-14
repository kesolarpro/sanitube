<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the endpoints that describe the environment.
 *
 * A readiness probe or a capability report tells an attacker which PHP
 * extensions, storage provider and database engine are in play. Until the
 * Identity module exists, these routes are protected by a shared token — and
 * when no token is configured they are not reachable at all, so a fresh
 * install never exposes them by accident.
 */
final class VerifyHealthToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('sanitube.health.token');

        if (! is_string($expected) || $expected === '') {
            abort(404);
        }

        $header = (string) config('sanitube.health.header', 'X-SaniTube-Health-Token');
        $provided = $request->header($header);

        if (! is_string($provided) || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid health token.');
        }

        return $next($request);
    }
}
