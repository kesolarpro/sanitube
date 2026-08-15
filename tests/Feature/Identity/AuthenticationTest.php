<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use SaniTube\Identity\Enums\UserRole;
use Tests\TestCase;

/**
 * Signing in, and the several ways it is refused.
 *
 * SaniTube holds unreleased masters and can hand a release to a distributor,
 * so the interface cannot be exposed without this. Most of what follows is
 * about the refusals — and about the ones that must be indistinguishable from
 * each other.
 */
final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-long-enough-passphrase';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login|owner@example.test|127.0.0.1');
    }

    #[Test]
    public function a_person_can_sign_in_and_out(): void
    {
        $user = $this->user();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function the_password_is_never_stored_in_plaintext(): void
    {
        $user = $this->user();

        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    #[Test]
    public function a_wrong_password_and_an_unknown_email_are_indistinguishable(): void
    {
        // A login form that says "no such account" is a form that confirms
        // which addresses have accounts, and enumeration is how a credential
        // stuffing run gets targeted.
        $user = $this->user();

        $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password']);
        $wrongPassword = $this->errorFor('email');

        $this->flushSession();
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => self::PASSWORD]);
        $unknownEmail = $this->errorFor('email');

        $this->assertNotSame('', $wrongPassword);
        $this->assertSame($wrongPassword, $unknownEmail);

        $this->assertGuest();
    }

    #[Test]
    public function a_deactivated_account_cannot_sign_in_and_says_no_more_than_that(): void
    {
        $user = $this->user();
        $user->forceFill(['is_active' => false])->save();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertGuest();
        $this->assertSame(__('identity.auth.failed'), $this->errorFor('email'));
    }

    #[Test]
    public function deactivating_an_account_ends_its_session_at_the_next_request(): void
    {
        // Checking only at sign-in is not enough: a remember-me cookie can
        // carry a session for weeks, so somebody deactivated on Monday would
        // still be working on Friday.
        $user = $this->user();
        $this->actingAs($user);

        $user->forceFill(['is_active' => false])->save();

        $this->get('/probe-active')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function repeated_failures_are_throttled(): void
    {
        $user = $this->user();

        foreach (range(1, 5) as $ignored) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        // Even the *correct* password is refused once locked out — otherwise
        // the lockout only slows down an attacker who is still guessing.
        $this->assertGuest();
        $this->assertStringContainsString('Too many', $this->errorFor('email'));
    }

    #[Test]
    public function throttling_is_scoped_to_the_email_so_one_attacker_cannot_lock_everybody_out(): void
    {
        $victim = $this->user();
        $other = $this->user(UserRole::Member, 'other@example.test');

        foreach (range(1, 6) as $ignored) {
            $this->post('/login', ['email' => $victim->email, 'password' => 'wrong']);
        }

        // A different account is unaffected: locking one email must not be a
        // way to deny service to the whole installation.
        $this->post('/login', ['email' => $other->email, 'password' => self::PASSWORD])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($other);
    }

    #[Test]
    public function signing_in_regenerates_the_session_identifier(): void
    {
        // Session fixation: an identifier issued before login must not survive
        // it.
        $user = $this->user();

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertNotSame($before, session()->getId());
    }

    #[Test]
    public function the_login_form_is_csrf_protected(): void
    {
        // Asserted structurally rather than behaviourally, and the reason is
        // worth stating: Laravel's ValidateCsrfToken short-circuits when
        // `runningUnitTests()` is true, so posting without a token in a test
        // succeeds regardless of the middleware stack. A behavioural assertion
        // here would pass on a route with no protection at all.
        //
        // So both halves are checked: the route is in the `web` group, and the
        // `web` group carries the CSRF middleware. Either can silently stop
        // being true on its own.
        $this->assertContains('web', $this->middlewareFor('login.store'));

        $kernel = app(Kernel::class);
        $groups = (new ReflectionClass($kernel))->getProperty('middlewareGroups')->getValue($kernel);

        $this->assertIsArray($groups);
        $this->assertContains(ValidateCsrfToken::class, $groups['web'] ?? []);
    }

    #[Test]
    public function an_authenticated_visitor_is_not_shown_the_login_form_again(): void
    {
        $this->actingAs($this->user())->get('/login')->assertRedirect('/');
    }

    // ---------------------------------------------------------------- roles

    #[Test]
    public function a_role_cannot_be_mass_assigned(): void
    {
        // The boundary that stops any future endpoint forwarding a request
        // body into create() from handing out OWNER.
        $user = User::query()->create([
            'name' => 'Sneaky',
            'email' => 'sneaky@example.test',
            'password' => self::PASSWORD,
            'role' => UserRole::Owner,
            'is_active' => true,
        ]);

        $this->assertSame(UserRole::Member, $user->refresh()->role);
    }

    #[Test]
    public function a_member_may_not_write_the_catalogue_or_distribute(): void
    {
        $member = UserRole::Member;

        $this->assertFalse($member->canWriteCatalogue());
        $this->assertFalse($member->canDistribute());
        $this->assertFalse($member->canAdminister());
    }

    #[Test]
    public function an_owner_may_do_everything_an_admin_may(): void
    {
        foreach ([UserRole::Owner, UserRole::Admin] as $role) {
            $this->assertTrue($role->canWriteCatalogue());
            $this->assertTrue($role->canDistribute());
            $this->assertTrue($role->canAdminister());
        }

        $this->assertTrue(UserRole::Owner->isOwner());
        $this->assertFalse(UserRole::Admin->isOwner());
    }

    #[Test]
    public function a_role_gate_refuses_a_member_and_admits_an_admin(): void
    {
        $this->actingAs($this->user(UserRole::Member))->get('/probe-distribute')->assertForbidden();
        $this->actingAs($this->user(UserRole::Admin, 'admin@example.test'))->get('/probe-distribute')->assertOk();
    }

    #[Test]
    public function an_unknown_ability_is_refused_rather_than_allowed(): void
    {
        // A typo in a route's middleware must not become an open door.
        $this->actingAs($this->user(UserRole::Owner))->get('/probe-unknown-ability')->assertForbidden();
    }

    // ---------------------------------------------------------- the command

    #[Test]
    public function the_console_command_creates_an_account_without_echoing_the_password(): void
    {
        $this->artisan('sanitube:user:create', ['--name' => 'Label', '--email' => 'label@example.test', '--role' => 'OWNER'])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertSuccessful();

        $user = User::query()->where('email', 'label@example.test')->firstOrFail();

        $this->assertSame(UserRole::Owner, $user->role);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    #[Test]
    public function the_command_refuses_a_short_password_and_a_mismatch(): void
    {
        $this->artisan('sanitube:user:create', ['--name' => 'A', '--email' => 'a@example.test'])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Confirm password', 'something-else-entirely')
            ->assertFailed();

        $this->artisan('sanitube:user:create', ['--name' => 'A', '--email' => 'a@example.test'])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertFailed();

        $this->assertSame(0, User::query()->where('email', 'a@example.test')->count());
    }

    // --------------------------------------------------------------- helpers

    private function user(UserRole $role = UserRole::Owner, string $email = 'owner@example.test'): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => $email,
            'password' => self::PASSWORD,
        ]);

        // forceFill because role is not mass-assignable — see the model.
        return $user->forceFill(['role' => $role, 'is_active' => true]);
    }

    /**
     * The first validation error on a field, read from the session.
     */
    private function errorFor(string $field): string
    {
        $errors = session('errors');

        return $errors instanceof ViewErrorBag ? (string) $errors->first($field) : '';
    }

    /**
     * @return list<string>
     */
    private function middlewareFor(string $routeName): array
    {
        $route = app('router')->getRoutes()->getByName($routeName);

        return $route === null ? [] : app('router')->gatherRouteMiddleware($route);
    }
}
