<?php

declare(strict_types=1);

namespace SaniTube\Identity\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns off a session the moment the account behind it is deactivated.
 *
 * `AuthenticateUser` checks `is_active` at sign-in, and that is not enough on
 * its own: a "remember me" cookie can carry a session for weeks, so somebody
 * deactivated on Monday would still be working on Friday. Checked on every
 * request, the deactivation takes effect at the next page load.
 */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => __('identity.auth.deactivated'),
            ]);
        }

        return $next($request);
    }
}
