<?php

declare(strict_types=1);

namespace SaniTube\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use SaniTube\Identity\Exceptions\PasswordResetFailed;
use SaniTube\Identity\Services\ResetPassword;

/**
 * Getting back in without an administrator.
 *
 * The request form **never says whether the address is known**. There is one
 * response, and a person who typed an address that has no account sees the
 * same page as one who typed their own. An unauthenticated form that answers
 * that question is an account enumerator, and on a platform with a handful of
 * named operators, knowing which addresses exist is most of the work.
 *
 * Blade rather than Inertia, like the sign-in page: these are the screens a
 * person reaches before the application has loaded, and on a shared host with
 * a broken build they are the ones that still have to work.
 */
final class PasswordResetController
{
    public function request(): View
    {
        return view('identity::password-request');
    }

    public function send(Request $request, ResetPassword $reset): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);

        try {
            $reset->request((string) $validated['email'], (string) $request->ip());
        } catch (PasswordResetFailed $exception) {
            throw ValidationException::withMessages([
                'email' => __($exception->getMessage(), ['seconds' => $exception->availableInSeconds]),
            ]);
        }

        // The same answer either way. Deliberately not "we sent you an email",
        // which would be a lie half the time and a disclosure the other half.
        return back()->with('status', __('identity.reset.requested'));
    }

    public function edit(Request $request, string $token): View
    {
        return view('identity::password-reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request, ResetPassword $reset): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            // `confirmed` needs `password_confirmation`; the length floor is
            // the installer's, so the two paths cannot disagree about what a
            // usable password is.
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        try {
            $reset->complete(
                email: (string) $validated['email'],
                token: (string) $validated['token'],
                password: (string) $validated['password'],
            );
        } catch (PasswordResetFailed $exception) {
            throw ValidationException::withMessages([
                'email' => __($exception->getMessage(), ['seconds' => $exception->availableInSeconds]),
            ]);
        }

        return redirect()->route('login')->with('status', __('identity.reset.done'));
    }
}
