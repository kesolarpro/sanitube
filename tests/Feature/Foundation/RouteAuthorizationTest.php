<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SEC-002. Every route is guarded, or is named here with a reason.
 *
 * The platform's authorisation lives on routes rather than in controllers, and
 * that is the right place for it — a controller that checks its own permission
 * is a controller somebody can forget to check. What the decision *needs* is
 * something that notices when a route arrives without one.
 *
 * **A route added without a guard is invisible.** Its own feature tests pass:
 * they sign somebody in before calling it, because that is how a person uses
 * it. Nothing in a per-feature suite asks "and what happens when nobody signs
 * in", and the answer stays unknown until it is found from outside.
 *
 * So this reads the router — the real one, after every module has registered —
 * and holds three claims:
 *
 *   - **Nothing writes without a guard.** Every `POST`, `PATCH`, `PUT` and
 *     `DELETE` carries a recognised one, from a closed set this file names.
 *   - **A web write names a role.** Signed in is not permission. A `MEMBER`
 *     may read the catalogue and may not change it, and the route is where
 *     that is decided.
 *   - **Nothing reads without a guard either.** A screen is a disclosure: the
 *     catalogue holds unreleased masters and the people credited on them.
 *
 * Every exemption is a line with a sentence. The list is short and each entry
 * is a thing somebody decided, not a thing somebody forgot — an exemption
 * without a reason fails as loudly as a missing guard.
 */
final class RouteAuthorizationTest extends TestCase
{
    /**
     * The middleware this platform accepts as "somebody was identified".
     *
     * Three kinds, because there are three sorts of caller: a person with a
     * session, another service holding the internal token, and a worker
     * holding its own. Each is a *different* credential on purpose — WRK-001
     * refused to let a worker present a catalogue token.
     *
     * @var list<string>
     */
    private const GUARDS = [
        'auth',
        'SaniTube\Api\Http\Middleware\VerifyInternalApiToken',
        'SaniTube\Worker\Http\Middleware\VerifyWorkerToken',
        'SaniTube\Api\Http\Middleware\VerifyHealthToken',
        'SaniTube\Installer\Http\Middleware\EnsureInstallable',
    ];

    /**
     * Routes that carry no guard, and why each one may not.
     *
     * @var array<string, string>
     */
    private const UNGUARDED = [
        'POST /login' => 'The sign-in form itself. Throttled at the route, and again in the service.',
        'GET /login' => 'The sign-in form itself.',
        'POST /logout' => 'Ending a session nobody may have. Behind CSRF like every web write; signing out twice is not an incident.',
        'GET /forgot-password' => 'Reached by somebody who cannot sign in. Throttled.',
        'POST /forgot-password' => 'Reached by somebody who cannot sign in. Answers identically for a known and an unknown address.',
        'GET /reset-password/{token}' => 'The token is the credential. Single-use, expiring.',
        'POST /reset-password' => 'The token is the credential. Single-use, expiring, and refused after a deactivation.',
        'GET /up' => 'The framework health endpoint. Answers whether the process is alive and nothing about this installation.',

        // A liveness probe has to answer before anything is configured, which
        // is the one thing a credential cannot help with. It reads no database,
        // no cache and no storage — asserted by a test that counts queries —
        // and carries its own file-backed throttle rather than the shared one,
        // because the shared one counts through the very store it must not
        // touch. Readiness is a different endpoint and is token-protected.
        'GET /api/v1/health/live' => 'A liveness probe. Answers from the process alone: no database, no cache, no storage, nothing about this installation.',

        // Laravel registers these for any local disk with `serve => true`.
        // The only such disk is the framework's own `local`; every disk
        // SaniTube stores on sets `serve => false`, which `SignedUrlAndSecrecyTest`
        // holds. Both check a relative signature before doing anything.
        'GET /storage/{path}' => 'Framework file serving, signature-checked. No SaniTube disk is served this way.',
        'PUT /storage/{path}' => 'Framework file receiving, signature-checked. No SaniTube disk is served this way.',
    ];

    /**
     * Web writes that carry a guard but name no role, and why.
     *
     * @var array<string, string>
     */
    private const WITHOUT_ROLE = [
        'POST /catalog/assets/{asset}/preview' => 'AssetPreviewPolicy decides, per asset kind: a master needs the catalogue role, a cover does not. A route-level role would be a second, coarser copy of that rule.',
        'POST /install' => 'The installer runs before there is anybody to have a role. EnsureInstallable is the guard, and it closes the door behind itself.',
    ];

    #[Test]
    public function nothing_writes_without_a_guard(): void
    {
        $checked = 0;

        foreach ($this->routes() as $signature => $route) {
            if (! $this->isWrite($route)) {
                continue;
            }

            $checked++;

            if (array_key_exists($signature, self::UNGUARDED)) {
                continue;
            }

            $this->assertTrue(
                $this->isGuarded($route),
                sprintf(
                    '[%s] changes something and identifies nobody. Add a guard, or name it in UNGUARDED with a reason.',
                    $signature,
                ),
            );
        }

        // A scan that found nothing would pass every assertion it does not
        // make. This platform has dozens of write routes.
        $this->assertGreaterThan(50, $checked, 'The router was read as nearly empty, so it was not read.');
    }

    #[Test]
    public function every_web_write_names_a_role(): void
    {
        $checked = 0;

        foreach ($this->routes() as $signature => $route) {
            if (! $this->isWrite($route) || ! $this->isWeb($route)) {
                continue;
            }

            if (array_key_exists($signature, self::UNGUARDED) || array_key_exists($signature, self::WITHOUT_ROLE)) {
                continue;
            }

            $checked++;

            $this->assertTrue(
                $this->namesARole($route),
                sprintf(
                    '[%s] is reachable by anyone signed in. Signed in is not permission — a MEMBER may read '
                        .'the catalogue and may not change it.',
                    $signature,
                ),
            );
        }

        $this->assertGreaterThan(30, $checked);
    }

    /**
     * A screen is a disclosure.
     *
     * The catalogue holds unreleased masters and the legal names of the people
     * credited on them. "It is only a read" is how those reach somebody who was
     * never signed in.
     */
    #[Test]
    public function nothing_reads_without_a_guard(): void
    {
        $checked = 0;

        foreach ($this->routes() as $signature => $route) {
            if ($this->isWrite($route) || array_key_exists($signature, self::UNGUARDED)) {
                continue;
            }

            $checked++;

            $this->assertTrue(
                $this->isGuarded($route),
                sprintf('[%s] answers anybody who asks.', $signature),
            );
        }

        $this->assertGreaterThan(20, $checked);
    }

    /**
     * An exemption is a decision, and a decision has a reason.
     *
     * Without this, the two lists above become a place to put anything that
     * fails — which is the same as not having them.
     */
    #[Test]
    public function every_exemption_says_why_and_still_exists(): void
    {
        $routes = $this->routes();

        foreach ([self::UNGUARDED, self::WITHOUT_ROLE] as $exemptions) {
            foreach ($exemptions as $signature => $reason) {
                $this->assertArrayHasKey(
                    $signature,
                    $routes,
                    sprintf('[%s] is exempted and no longer exists. An exemption for nothing hides the next one.', $signature),
                );

                $this->assertGreaterThan(
                    20,
                    strlen(trim($reason)),
                    sprintf('[%s] is exempted with something that is not a reason.', $signature),
                );
            }
        }
    }

    /**
     * And an unguarded route is never a *write* that a role would have caught.
     *
     * The two lists mean different things: UNGUARDED says nobody is
     * identified, WITHOUT_ROLE says somebody is but no role is named. A route
     * in both would be exempted twice for one decision.
     */
    #[Test]
    public function no_route_is_exempted_twice(): void
    {
        $both = array_intersect_key(self::UNGUARDED, self::WITHOUT_ROLE);

        $this->assertSame([], $both, 'A route is exempted in both lists.');
    }

    // ------------------------------------------------------------ fixtures

    /**
     * Every route, keyed by the signature the exemption lists use.
     *
     * @return array<string, Route>
     */
    private function routes(): array
    {
        $routes = [];

        // `getRoutes()` is typed as the collection interface rather than as
        // something iterable; the concrete collection is, and asking it for its
        // routes says so without widening anything.
        foreach (Router::getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD' || $method === 'OPTIONS') {
                    continue;
                }

                $routes[$method.' /'.ltrim($route->uri(), '/')] = $route;
            }
        }

        return $routes;
    }

    private function isWrite(Route $route): bool
    {
        return array_intersect(['POST', 'PATCH', 'PUT', 'DELETE'], $route->methods()) !== [];
    }

    private function isWeb(Route $route): bool
    {
        return in_array('web', $this->middlewareOf($route), true);
    }

    private function isGuarded(Route $route): bool
    {
        return array_intersect(self::GUARDS, $this->middlewareOf($route)) !== [];
    }

    /**
     * Whether the route names one of the three abilities.
     *
     * The ability is read from the middleware argument rather than matched
     * loosely: `can.role:catalogue` and `can.role:distribute` are different
     * permissions, and a check that accepted "something that starts with
     * can.role" would accept a typo that grants nothing.
     */
    private function namesARole(Route $route): bool
    {
        foreach ($this->middlewareOf($route) as $middleware) {
            if (! str_starts_with($middleware, 'can.role:')) {
                continue;
            }

            if (in_array(substr($middleware, strlen('can.role:')), ['catalogue', 'distribute', 'administer'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What actually runs for this route.
     *
     * **Minus what it excludes**, and that subtraction is the whole point.
     * `gatherMiddleware()` reports the group's middleware whether or not the
     * route opted out of it, so a route that says `withoutMiddleware('auth')`
     * still *looks* guarded to anything that only reads what it gathers.
     *
     * The first version of this file made exactly that mistake, and two
     * mutations — one removing a role guard, one removing `auth` — walked
     * straight past it. A test that cannot see a route dropping its guard is a
     * test that certifies the hole it was written to find.
     *
     * @return list<string>
     */
    private function middlewareOf(Route $route): array
    {
        $excluded = $route->excludedMiddleware();

        $middleware = array_unique([
            ...$route->middleware(),
            ...$route->gatherMiddleware(),
        ]);

        return array_values(array_filter(
            $middleware,
            static fn (mixed $name): bool => is_string($name) && ! in_array($name, $excluded, true),
        ));
    }
}
