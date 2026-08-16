<?php

declare(strict_types=1);

namespace SaniTube\Installer\Http\Middleware;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The installer cannot use the database it is being run to configure.
 *
 * SaniTube ships `SESSION_DRIVER=database` and `CACHE_STORE=database`, which
 * are the right defaults for a running installation and are impossible for the
 * one screen that runs *before* the schema exists. Without this the sequence is:
 * the install page renders, Laravel tries to store the session that carries the
 * CSRF token, there is no `sessions` table, and the form cannot be submitted —
 * on precisely the host the web installer exists for.
 *
 * So the installer's own routes run their session and cache on the filesystem.
 * This is not a weakening: `storage/framework` is the same directory the
 * installer's token already lives in, and it is writable by definition or
 * nothing works at all.
 *
 * **It has to run before the session starts**, which is why the installer
 * routes declare their middleware explicitly rather than joining the `web`
 * group. In the group, `StartSession` would already have read the driver by
 * the time any route middleware ran.
 */
final readonly class UseFilesystemState
{
    public function __construct(private Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->config->set('session.driver', 'file');
        $this->config->set('cache.default', 'file');

        return $next($request);
    }
}
