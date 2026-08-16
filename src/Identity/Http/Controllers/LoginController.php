<?php

declare(strict_types=1);

namespace SaniTube\Identity\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Identity\Exceptions\AuthenticationFailed;
use SaniTube\Identity\Http\Requests\LoginRequest;
use SaniTube\Identity\Services\AuthenticateUser;

/**
 * Signing in and out.
 *
 * Deliberately not an API-token endpoint. The first-party interface runs on a
 * session cookie, and the internal API token that ING/CAT/GEN/DIST use is a
 * *server-to-server* credential — copying it into a browser would put a
 * catalogue-mutating secret in JavaScript that any extension can read.
 */
final class LoginController
{
    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->intended('/');
        }

        return view('identity::login');
    }

    public function store(LoginRequest $request, AuthenticateUser $authenticator): RedirectResponse
    {
        try {
            $authenticator->handle(
                email: (string) $request->input('email'),
                password: (string) $request->input('password'),
                remember: $request->boolean('remember'),
                ip: (string) $request->ip(),
            );
        } catch (AuthenticationFailed $exception) {
            // Reported against `email` because that is the field a person
            // looks at, and with a translated message — never a raw sentence.
            throw ValidationException::withMessages([
                'email' => __($exception->getMessage(), ['seconds' => $exception->availableInSeconds]),
            ]);
        }

        return redirect()->intended('/');
    }

    public function destroy(Request $request, RecordAuditEvent $audit): RedirectResponse
    {
        $user = Auth::user();

        // Before the logout, while the guard can still say who this is. A
        // sign-out recorded afterwards would be attributed to a guest and
        // would name nobody, which is the one fact it exists to carry.
        if ($user instanceof User) {
            $audit->record(AuditAction::UserSignedOut, subjectUuid: $user->uuid);
        }

        Auth::logout();

        // Both, and in this order. Invalidating drops the session data;
        // regenerating the token stops the old CSRF token being replayed
        // against the next session.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
