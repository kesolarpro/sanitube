<?php

declare(strict_types=1);

namespace SaniTube\Identity\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use SaniTube\Identity\Enums\UserRole;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route on what the signed-in role may do.
 *
 * Used as `can.role:distribute`. The abilities are the ones {@see UserRole}
 * actually names — write the catalogue, distribute, administer — rather than a
 * permission string per route, because a permission per route is a list nobody
 * keeps in step with the routes.
 */
final class EnsureUserCan
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(401);
        }

        $permitted = match ($ability) {
            'catalogue' => $user->role->canWriteCatalogue(),
            'distribute' => $user->role->canDistribute(),
            'administer' => $user->role->canAdminister(),
            // An unknown ability is a routing mistake, and defaulting to
            // "allowed" would make a typo into an open door.
            default => false,
        };

        if (! $permitted) {
            abort(403, __('identity.auth.forbidden'));
        }

        return $next($request);
    }
}
