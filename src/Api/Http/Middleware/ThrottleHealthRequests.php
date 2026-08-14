<?php

declare(strict_types=1);

namespace SaniTube\Api\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiting for the health endpoints, on a store of its own.
 *
 * Laravel's `throttle` middleware counts through the default cache store,
 * which in a portable install is the database. That is fine for the API at
 * large and wrong for a liveness probe: it would make the probe fail whenever
 * the database fails, turning the one endpoint that answers "the process is
 * alive" into another way of asking "is the database up?".
 *
 * This middleware counts through a store chosen for the purpose — the file
 * cache by default — so liveness stays answerable while everything behind it
 * is down, and still cannot be used as an amplification target.
 *
 * Fixed window: `add` creates the counter with the window's TTL, `increment`
 * advances it, and the counter expires on its own. Nothing extends the window
 * under sustained load, so a client is never locked out indefinitely.
 */
final readonly class ThrottleHealthRequests
{
    public function __construct(private CacheFactory $cache) {}

    public function handle(Request $request, Closure $next, string $limiter = 'live'): Response
    {
        $config = (array) config('sanitube.health.throttle.'.$limiter, []);

        $maxAttempts = (int) ($config['max_attempts'] ?? 60);
        $decaySeconds = (int) ($config['decay_seconds'] ?? 60);

        $store = $this->store();
        $key = $this->key($limiter, $request);

        $store->add($key, 0, $decaySeconds);
        $hits = (int) $store->increment($key);

        if ($hits > $maxAttempts) {
            return response()->json(
                ['message' => 'Too many requests.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $decaySeconds],
            );
        }

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $maxAttempts - $hits));

        return $response;
    }

    private function store(): Repository
    {
        $store = config('sanitube.health.throttle.store');

        return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
    }

    private function key(string $limiter, Request $request): string
    {
        return 'sanitube:health-throttle:'.$limiter.':'.sha1((string) $request->ip());
    }
}
