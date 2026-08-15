<?php

declare(strict_types=1);

namespace SaniTube\Identity\Services;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SaniTube\Identity\Exceptions\AuthenticationFailed;

/**
 * Signing in, and the three ways it is refused.
 *
 * **The three refusals answer with the same message.** Wrong password, unknown
 * email and deactivated account are indistinguishable from outside, because a
 * login form that says "no such account" is a login form that confirms which
 * addresses have accounts. The *logs* distinguish them; the response does not.
 *
 * **Throttling is per email *and* per IP.** Per-IP alone lets a botnet spread
 * one account's attempts across a thousand addresses; per-email alone lets one
 * address work through a list of accounts unimpeded. Neither is sufficient and
 * both are cheap.
 *
 * The session is regenerated on success. Without it, a session identifier
 * issued before login is still valid after it, which is session fixation.
 */
final readonly class AuthenticateUser
{
    /** Attempts before an email/IP pair is locked out. */
    public const MAX_ATTEMPTS = 5;

    /** How long a lockout lasts. */
    public const DECAY_SECONDS = 60;

    public function handle(string $email, string $password, bool $remember, string $ip): User
    {
        $key = $this->throttleKey($email, $ip);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            event(new Lockout(request()));

            throw AuthenticationFailed::throttled(RateLimiter::availableIn($key));
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password], $remember)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw AuthenticationFailed::invalidCredentials();
        }

        $user = Auth::user();

        if (! $user instanceof User || ! $user->is_active) {
            // Authenticated, but not permitted. The session is discarded
            // rather than left half-established, and the attempt still counts
            // towards the throttle so a deactivated account cannot be used to
            // probe passwords for free.
            Auth::logout();
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw AuthenticationFailed::invalidCredentials();
        }

        RateLimiter::clear($key);

        // Session fixation: an identifier issued before login must not survive
        // it.
        request()->session()->regenerate();

        $user->forceFill(['last_login_at' => Carbon::now()])->save();

        return $user;
    }

    /**
     * Per email *and* per IP. See the class docblock for why neither alone is
     * enough.
     */
    private function throttleKey(string $email, string $ip): string
    {
        return 'login|'.Str::lower($email).'|'.$ip;
    }
}
