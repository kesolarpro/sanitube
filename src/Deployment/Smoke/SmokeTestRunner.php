<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Smoke;

use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Real HTTP against the deployed installation — the only proof that counts
 * after a deploy.
 *
 * Everything before this point checked the machine from the inside: the
 * doctor reads configuration, the deploy reads the schema. None of that
 * proves a request through the actual web server, the actual document root
 * and the actual TLS reaches the application — or, just as load-bearing,
 * that a request for `/.env` does *not* reach anything.
 *
 * Read-only by construction: every request is a GET, nothing authenticates,
 * nothing carries a body. The negative checks treat *any* 2xx as failure —
 * a 200 that serves the wrong thing is worse than a 404, and a misconfigured
 * document root serves `.env` with a cheerful status line.
 */
final readonly class SmokeTestRunner
{
    /**
     * Paths that must never answer 2xx. Each is a real file on every
     * installation, which is exactly why serving it is a catastrophe and
     * not a 404-shaped hypothetical.
     */
    private const FORBIDDEN = [
        '/.env',
        '/.git/config',
        '/composer.json',
        '/storage/logs/laravel.log',
        '/config/app.php',
        '/vendor/autoload.php',
    ];

    public function __construct(
        private HttpFactory $http,
        private string $publicPath,
        private int $timeout = 10,
    ) {}

    /**
     * @return list<SmokeCheck>
     */
    public function run(string $baseUrl): array
    {
        $base = rtrim($baseUrl, '/');

        $checks = [
            $this->loginRenders($base),
            $this->rootRedirectsToLogin($base),
            $this->healthAnswers($base),
            $this->frontendAssetServes($base),
        ];

        foreach (self::FORBIDDEN as $path) {
            $checks[] = $this->forbidden($base, $path);
        }

        return $checks;
    }

    private function loginRenders(string $base): SmokeCheck
    {
        try {
            $response = $this->http->timeout($this->timeout)->get($base.'/login');
        } catch (Throwable $exception) {
            return SmokeCheck::failed('login_renders', $this->unreachable($exception));
        }

        if ($response->status() !== 200) {
            return SmokeCheck::failed('login_renders', sprintf('/login answered %d, not 200.', $response->status()));
        }

        return SmokeCheck::passed('login_renders', '/login answers 200.');
    }

    private function rootRedirectsToLogin(string $base): SmokeCheck
    {
        try {
            $response = $this->http->timeout($this->timeout)->withoutRedirecting()->get($base.'/');
        } catch (Throwable $exception) {
            return SmokeCheck::failed('auth_redirect', $this->unreachable($exception));
        }

        // An unauthenticated dashboard answering 200 means the auth layer is
        // not between the internet and the catalogue, which is not a smoke
        // failure so much as a fire.
        if ($response->status() === 200) {
            return SmokeCheck::failed('auth_redirect', 'The dashboard answered 200 to an unauthenticated request.');
        }

        if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
            return SmokeCheck::failed('auth_redirect', sprintf('/ answered %d, neither a redirect nor a refusal.', $response->status()));
        }

        return SmokeCheck::passed('auth_redirect', sprintf('/ redirects unauthenticated requests (%d).', $response->status()));
    }

    private function healthAnswers(string $base): SmokeCheck
    {
        try {
            $response = $this->http->timeout($this->timeout)->get($base.'/up');
        } catch (Throwable $exception) {
            return SmokeCheck::failed('health', $this->unreachable($exception));
        }

        return $response->status() === 200
            ? SmokeCheck::passed('health', '/up answers 200.')
            : SmokeCheck::failed('health', sprintf('/up answered %d.', $response->status()));
    }

    /**
     * A hashed asset named by the local manifest must come back over HTTP.
     * This is the check that catches a document root pointing at the wrong
     * directory while `/login` happens to work through a front controller.
     */
    private function frontendAssetServes(string $base): SmokeCheck
    {
        $raw = @file_get_contents($this->publicPath.'/build/manifest.json');

        if ($raw === false) {
            return SmokeCheck::skipped('frontend_asset', 'No build manifest on this machine; install the frontend first.');
        }

        $manifest = json_decode($raw, true);

        if (! is_array($manifest)) {
            return SmokeCheck::failed('frontend_asset', 'The local manifest is unreadable, which the frontend installer should have refused.');
        }

        foreach ($manifest as $entry) {
            if (is_array($entry) && is_string($entry['file'] ?? null)) {
                $path = '/build/'.ltrim($entry['file'], '/');

                try {
                    $response = $this->http->timeout($this->timeout)->get($base.$path);
                } catch (Throwable $exception) {
                    return SmokeCheck::failed('frontend_asset', $this->unreachable($exception));
                }

                if ($response->status() !== 200) {
                    return SmokeCheck::failed('frontend_asset', sprintf('[%s] answered %d; the built bundle is not being served.', $path, $response->status()));
                }

                return SmokeCheck::passed('frontend_asset', sprintf('[%s] serves.', $path));
            }
        }

        return SmokeCheck::failed('frontend_asset', 'The manifest names no file entries.');
    }

    private function forbidden(string $base, string $path): SmokeCheck
    {
        $name = 'forbidden:'.$path;

        try {
            $response = $this->http->timeout($this->timeout)->withoutRedirecting()->get($base.$path);
        } catch (Throwable $exception) {
            return SmokeCheck::failed($name, $this->unreachable($exception));
        }

        if ($response->status() >= 200 && $response->status() < 300) {
            return SmokeCheck::failed($name, sprintf(
                '%s answered %d. The document root or server rules are serving files that must never leave the machine.',
                $path,
                $response->status(),
            ));
        }

        return SmokeCheck::passed($name, sprintf('%s is refused (%d).', $path, $response->status()));
    }

    /**
     * Connection failures come back with the client's own words, which can
     * carry the resolved address; the name of the check already says what
     * was being reached, so the reason stays generic on purpose.
     */
    private function unreachable(Throwable $exception): string
    {
        return 'The request did not complete: connection failed or timed out.';
    }
}
