<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Getting back in without an administrator.
 *
 * SaniTube has no self-registration, so this is the only path that creates a
 * session for somebody the platform did not just authenticate — which makes it
 * the surface most worth being careful about.
 *
 * Two claims carry most of these tests:
 *
 *   - **The request never says whether the address is known.** An
 *     unauthenticated form that answers that question is an account
 *     enumerator, and on a platform with a handful of named operators, knowing
 *     which addresses exist is most of the work of attacking it.
 *   - **A deactivated account cannot reset its way back in.** SEC-001
 *     deactivates rather than deletes so that access stops; a reset that
 *     restored it would make deactivation a suggestion, and the person
 *     deactivated is exactly the person most likely to try.
 */
final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        RateLimiter::clear('password-reset|owner@example.test|127.0.0.1');
    }

    // ------------------------------------------------------- asking for one

    #[Test]
    public function a_known_address_is_sent_a_link(): void
    {
        $user = $this->user();

        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    #[Test]
    public function an_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        // Byte-for-byte the same response as a known address. Anything else —
        // a different message, a different status, a different field in error
        // — is an enumeration oracle.
        $known = $this->post('/forgot-password', ['email' => $this->user()->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.test']);

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame(
            $known->headers->get('Location'),
            $unknown->headers->get('Location'),
        );

        // And no error against the field. Status and location match a
        // validation failure too — a refusal redirects back exactly like a
        // success — so the error bag is where an enumeration oracle would
        // actually be, and where this has to look.
        $unknown->assertSessionHasNoErrors();
        $known->assertSessionHasNoErrors();

        Notification::assertSentTimes(ResetPasswordNotification::class, 1);
    }

    #[Test]
    public function a_deactivated_account_is_sent_nothing_and_told_nothing(): void
    {
        $user = $this->user();
        $user->forceFill(['is_active' => false])->save();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Not a different message, not a refusal. Saying "that account is
        // disabled" confirms the address exists *and* tells somebody they have
        // found a live target.
        Notification::assertNothingSent();
    }

    #[Test]
    public function asking_too_often_is_the_one_thing_said_out_loud(): void
    {
        // A fact about the caller rather than about any account, so it is safe
        // to state.
        $email = $this->user()->email;

        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => $email]);
        }

        $this->post('/forgot-password', ['email' => $email])
            ->assertSessionHasErrors('email');
    }

    // ---------------------------------------------------------- completing

    #[Test]
    public function a_valid_token_changes_the_password(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('a-brand-new-passphrase', (string) $user->refresh()->password));
    }

    #[Test]
    public function the_new_password_works_and_the_old_one_does_not(): void
    {
        $user = $this->user();

        $this->reset($user, 'a-brand-new-passphrase');

        $this->post('/login', ['email' => $user->email, 'password' => 'a-long-enough-passphrase'])
            ->assertSessionHasErrors('email');

        $this->post('/login', ['email' => $user->email, 'password' => 'a-brand-new-passphrase'])
            ->assertRedirect();
    }

    #[Test]
    public function a_token_cannot_be_used_twice(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $this->submit($user->email, $token, 'a-brand-new-passphrase')->assertRedirect(route('login'));

        // The second attempt is refused, and refused the same way a forged
        // token is — the holder of a spent token has earned no more detail
        // than the holder of a made-up one.
        $this->submit($user->email, $token, 'another-passphrase-entirely')
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('a-brand-new-passphrase', (string) $user->refresh()->password));
    }

    #[Test]
    public function an_invented_token_is_refused(): void
    {
        $user = $this->user();

        $this->submit($user->email, str_repeat('a', 64), 'a-brand-new-passphrase')
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('a-long-enough-passphrase', (string) $user->refresh()->password));
    }

    #[Test]
    public function an_expired_token_is_refused(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $this->travel((int) config('auth.passwords.users.expire', 60) + 5)->minutes();

        $this->submit($user->email, $token, 'a-brand-new-passphrase')
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('a-long-enough-passphrase', (string) $user->refresh()->password));
    }

    #[Test]
    public function an_account_deactivated_after_the_link_was_sent_cannot_use_it(): void
    {
        // The window this closes: a link issued while the account was live,
        // used after it was not. The token is consumed either way; what must
        // not happen is the password changing.
        $user = $this->user();
        $token = Password::createToken($user);

        $user->forceFill(['is_active' => false])->save();

        $this->submit($user->email, $token, 'a-brand-new-passphrase')
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('a-long-enough-passphrase', (string) $user->refresh()->password));
    }

    #[Test]
    public function a_reset_ends_the_sessions_opened_with_the_old_password(): void
    {
        // A reset is often performed *because* somebody else has one.
        $user = $this->user();
        $before = (string) $user->remember_token;

        $this->reset($user, 'a-brand-new-passphrase');

        $this->assertNotSame($before, (string) $user->refresh()->remember_token);
    }

    #[Test]
    public function a_short_password_is_refused(): void
    {
        $user = $this->user();

        $this->submit($user->email, Password::createToken($user), 'short')
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('a-long-enough-passphrase', (string) $user->refresh()->password));
    }

    // ------------------------------------------------------------- screens

    #[Test]
    public function both_screens_render_before_the_application_has_loaded(): void
    {
        // Blade, like the sign-in page: on a shared host with a broken build
        // these are the screens that still have to work.
        $this->get('/forgot-password')->assertOk();
        $this->get('/reset-password/'.str_repeat('a', 64))->assertOk();
    }

    #[Test]
    public function no_screen_reveals_whether_an_address_has_an_account(): void
    {
        $response = $this->post('/forgot-password', ['email' => 'nobody@example.test'])
            ->assertRedirect();

        $this->followRedirects($response)
            ->assertDontSee('no account', escape: false)
            ->assertDontSee('not found', escape: false);
    }

    // ------------------------------------------------------------ fixtures

    /**
     * @return TestResponse<Response>
     */
    private function submit(string $email, string $token, string $password): TestResponse
    {
        return $this->post('/reset-password', [
            'token' => $token,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    private function reset(User $user, string $password): void
    {
        $this->submit($user->email, Password::createToken($user), $password)
            ->assertRedirect(route('login'));
    }

    private function user(): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'owner@example.test'],
            ['name' => 'Owner', 'password' => 'a-long-enough-passphrase'],
        );

        $user->forceFill(['role' => UserRole::Owner, 'is_active' => true])->save();

        return $user;
    }
}
