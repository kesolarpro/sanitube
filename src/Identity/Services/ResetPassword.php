<?php

declare(strict_types=1);

namespace SaniTube\Identity\Services;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset as PasswordWasReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SaniTube\Identity\Exceptions\PasswordResetFailed;

/**
 * Getting back in without an administrator.
 *
 * SaniTube has no self-registration, so this is the only path that creates a
 * session for somebody the platform did not just authenticate — which makes it
 * the surface most worth being careful about.
 *
 * **The request never says whether the address is known.** Not "no account with
 * that email", not a different delay, not a different status code. An
 * unauthenticated form that answers that question is an account enumerator, and
 * on a platform with a handful of named operators, knowing *which* addresses
 * exist is most of the work of attacking it.
 *
 * **A deactivated account gets no email.** SEC-001 deactivates rather than
 * deletes, precisely so that references stay resolvable and access stops. A
 * reset that let somebody back in would make deactivation a suggestion, and
 * the person deactivated is exactly the person most likely to try. The response
 * is identical to the unknown-address one.
 *
 * **Laravel's broker does the cryptography.** Token generation, hashing,
 * expiry and single use are its job and it is already right; inventing any of
 * it here would be inventing crypto to save a dependency that ships with the
 * framework.
 *
 * What is added on top is the throttle, the deactivation check, and the two
 * things a reset must do to existing access: change the password *and* cut the
 * sessions that were opened with the old one.
 */
final readonly class ResetPassword
{
    /** Requests before an email/IP pair is locked out. */
    public const MAX_ATTEMPTS = 5;

    /** How long a lockout lasts. */
    public const DECAY_SECONDS = 900;

    /**
     * Send a reset link, or quietly do nothing.
     *
     * Returns nothing on purpose. There is no answer to give that does not
     * also answer "does this address exist here".
     *
     * @throws PasswordResetFailed when the caller is asking too often
     */
    public function request(string $email, string $ip): void
    {
        $key = 'password-reset|'.Str::lower($email).'|'.$ip;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            // The one thing that *is* said out loud, because it is a fact
            // about the caller rather than about the account.
            throw PasswordResetFailed::throttled(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User || ! $user->is_active) {
            // Silently. A deactivated account must not be able to reset its
            // way back in, and saying so would confirm the address exists.
            return;
        }

        Password::sendResetLink(['email' => $email]);
    }

    /**
     * Complete a reset.
     *
     * @throws PasswordResetFailed
     */
    public function complete(string $email, string $token, string $password): User
    {
        $reset = null;

        $status = Password::reset(
            ['email' => $email, 'password' => $password, 'password_confirmation' => $password, 'token' => $token],
            function (User $user, string $password) use (&$reset): void {
                if (! $user->is_active) {
                    // Reachable when an account is deactivated between the
                    // link being sent and used. The token is consumed either
                    // way; what must not happen is the password changing.
                    return;
                }

                $user->forceFill([
                    'password' => Hash::make($password),
                    // Any session opened with the old password is no longer
                    // the person who owns the account — a reset is often
                    // performed *because* somebody else has one.
                    'remember_token' => Str::random(60),
                ])->save();

                $reset = $user;

                event(new PasswordWasReset($user));
            },
        );

        if (! $reset instanceof User) {
            // One refusal for every way a token can be unusable: wrong,
            // expired, already used, or belonging to an account that has since
            // been deactivated. Telling them apart would say more about the
            // account than the person holding a bad token has earned.
            throw PasswordResetFailed::invalidToken($status);
        }

        Auth::logoutOtherDevices($password);

        return $reset;
    }
}
