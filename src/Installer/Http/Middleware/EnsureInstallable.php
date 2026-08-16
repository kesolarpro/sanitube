<?php

declare(strict_types=1);

namespace SaniTube\Installer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SaniTube\Installer\Services\InstallationLock;
use SaniTube\Installer\Services\InstallationService;
use Symfony\Component\HttpFoundation\Response;

/**
 * The installer exists only while there is nothing to protect.
 *
 * Two questions, and both have to answer yes.
 *
 * **Is the lock absent?** The file the installer writes when it finishes. It
 * is permanent by intent: nothing in the application removes it, and an
 * operator who genuinely means to install again has to delete it deliberately
 * from a shell or a file manager — which is the same access the installer's
 * token demands anyway.
 *
 * **Is the installation incomplete?** Asked of the installation itself, not of
 * the lock. A lock file can be deleted; an installation with a live owner
 * account and a populated schema must refuse to be installed over regardless
 * of what any file says. This is the guard that matters, and the lock is the
 * cheap check in front of it.
 *
 * A refusal is a 404, not a 403. There is nothing behind this URL for whoever
 * is asking, and a 403 would confirm that a SaniTube installer once lived
 * here — which is an invitation to come back and look for the lock file.
 */
final readonly class EnsureInstallable
{
    public function __construct(
        private InstallationLock $lock,
        private InstallationService $installation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->lock->isLocked()) {
            abort(404);
        }

        // `isComplete()` is key, database, schema *and* an active owner. A
        // half-installed system is still installable — that is the case the
        // operator is most often in when they open this page.
        if ($this->installation->status()->isComplete()) {
            abort(404);
        }

        return $next($request);
    }
}
