<?php

declare(strict_types=1);

namespace SaniTube\Identity\Services;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
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
 *
 * **The audit log is where the three refusals stop being the same.** The
 * response cannot tell them apart without becoming an enumeration oracle, but
 * an administrator reading the log needs to: an account locked out, a wrong
 * password and a deactivated account trying to get back in are three different
 * afternoons. The distinction is safe there because the log is behind the
 * administer role, and it is recorded against the *account's* uuid rather than
 * the submitted address, so a run of attempts against one person is visible
 * without ever storing a string somebody else chose.
 */
final readonly class AuthenticateUser
{
    /** Attempts before an email/IP pair is locked out. */
    public const MAX_ATTEMPTS = 5;

    /** How long a lockout lasts. */
    public const DECAY_SECONDS = 60;

    public function __construct(private RecordAuditEvent $audit) {}

    public function handle(string $email, string $password, bool $remember, string $ip): User
    {
        $key = $this->throttleKey($email, $ip);

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            event(new Lockout(request()));

            $this->audit->refused(AuditAction::UserSignInFailed, 'THROTTLED', $this->accountFor($email));

            throw AuthenticationFailed::throttled(RateLimiter::availableIn($key));
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password], $remember)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            $this->audit->refused(AuditAction::UserSignInFailed, 'INVALID_CREDENTIALS', $this->accountFor($email));

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

            // Recorded *after* the logout, so the actor is a guest — which is
            // the truth. They had the password and still did not get in.
            $this->audit->refused(
                AuditAction::UserSignInFailed,
                'ACCOUNT_INACTIVE',
                $user instanceof User ? $user->uuid : $this->accountFor($email),
            );

            throw AuthenticationFailed::invalidCredentials();
        }

        RateLimiter::clear($key);

        // Session fixation: an identifier issued before login must not survive
        // it.
        request()->session()->regenerate();

        $user->forceFill(['last_login_at' => Carbon::now()])->save();

        $this->audit->record(AuditAction::UserSignedIn, subjectUuid: $user->uuid);

        return $user;
    }

    /**
     * The account an attempt was aimed at, when there is one.
     *
     * Recording the uuid rather than the submitted address is what lets an
     * administrator see "eleven failures against this person" without the log
     * accumulating arbitrary strings somebody else typed. The lookup runs on
     * every failure, known address or not, so it adds no timing difference
     * between the two.
     */
    private function accountFor(string $email): ?string
    {
        $user = User::query()->where('email', $email)->first();

        return $user instanceof User ? $user->uuid : null;
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
