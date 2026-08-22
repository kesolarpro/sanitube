<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Identity\Exceptions\UserAdministrationException;
use SaniTube\Identity\Services\ManageUsers;
use Tests\TestCase;

/**
 * Who may use this installation, and who decides.
 *
 * USR-001. The platform shipped three roles and no way to assign any of them:
 * `sanitube:user:create` over SSH was the only door. Worse, the enum's own
 * docblock claimed "deactivating the last owner is refused" and **no code
 * enforced it** — so an installation could be locked out of its own
 * administration by a single click that did not exist yet.
 *
 * Three invariants carry this file, and they are the reason it exists rather
 * than the screen:
 *
 *   - **The last owner cannot be removed, deactivated or demoted.** The
 *     failure it prevents is total: an installation with no active owner has
 *     no way back except a shell on the server, which is what the owner role
 *     exists to make unnecessary.
 *   - **Owners are owners' business.** An administrator who could promote
 *     themselves would *be* an owner.
 *   - **Nobody administers themselves.** The mistake is silent — the screen
 *     reloads, the session is still valid, and nothing looks wrong until the
 *     next sign-in.
 */
final class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------- the ownership root

    /**
     * The rule is reached by two requests, not by one.
     *
     * A single request cannot get here. Only an owner may touch an owner, and
     * nobody administers themselves — so when there is exactly one active
     * owner left, the only person permitted to remove them is the one person
     * forbidden from asking. The screen tests below state that surface rule.
     *
     * The rule below it exists for the case those two guards do not cover:
     * **two owners removing each other at the same time.** Each request is
     * legitimate on its own and each sees the other owner still in place. One
     * of them commits first, and the second must be refused by what is in the
     * table rather than by what its actor believed when it started.
     *
     * These tests reproduce that by holding the second actor as it was loaded
     * — an owner — and calling the service after the first change has
     * committed. That is precisely what a concurrent request holds: a user
     * model read at the start of a request that is no longer true.
     */
    #[Test]
    public function two_owners_demoting_each_other_at_once_leaves_one_standing(): void
    {
        $first = $this->user(UserRole::Owner, 'first@sanitube.test');
        $second = $this->user(UserRole::Owner, 'second@sanitube.test');

        $users = $this->users();

        // Read before anything commits, which is what the concurrent request
        // is holding: itself, as it was when it started.
        $secondsOwnRequest = $this->asItsOwnRequestSeesThem($second);

        // The first request commits. Two owners at the time it was decided, so
        // it is right to allow it.
        $users->changeRole($first, $second, UserRole::Admin);

        // The second was decided at the same moment and still believes it is
        // an owner acting on an owner. The table disagrees.
        $refusal = $this->refusal(fn () => $users->changeRole($secondsOwnRequest, $first, UserRole::Admin));

        $this->assertSame('LAST_OWNER', $refusal->reason);
        $this->assertSame(UserRole::Owner, $first->fresh()?->role);
        $this->assertSame(1, $this->activeOwners());
    }

    #[Test]
    public function two_owners_deactivating_each_other_at_once_leaves_one_signed_in(): void
    {
        $first = $this->user(UserRole::Owner, 'first@sanitube.test');
        $second = $this->user(UserRole::Owner, 'second@sanitube.test');

        $users = $this->users();

        $secondsOwnRequest = $this->asItsOwnRequestSeesThem($second);

        $users->setActive($first, $second, false);

        $refusal = $this->refusal(fn () => $users->setActive($secondsOwnRequest, $first, false));

        $this->assertSame('LAST_OWNER', $refusal->reason);
        $this->assertTrue($first->fresh()?->is_active);
        $this->assertSame(1, $this->activeOwners());
    }

    #[Test]
    public function a_deactivated_owner_is_not_a_way_back_in(): void
    {
        // An owner who cannot sign in is not somebody who can administer the
        // installation, whatever the role column says. Counting them would
        // leave a platform whose only owner is locked out of it — and the
        // middleware turns their next request into a sign-out, so there is no
        // session to rescue it with either.
        $first = $this->user(UserRole::Owner, 'first@sanitube.test');
        $second = $this->user(UserRole::Owner, 'second@sanitube.test');
        $retired = $this->user(UserRole::Owner, 'retired@sanitube.test');

        $users = $this->users();
        $users->setActive($first, $retired, false);

        $secondsOwnRequest = $this->asItsOwnRequestSeesThem($second);

        $users->changeRole($first, $second, UserRole::Admin);

        $refusal = $this->refusal(fn () => $users->changeRole($secondsOwnRequest, $first, UserRole::Admin));

        $this->assertSame('LAST_OWNER', $refusal->reason);
        $this->assertSame(UserRole::Owner, $first->fresh()?->role);
        $this->assertSame(1, $this->activeOwners());
    }

    #[Test]
    public function promoting_somebody_is_never_the_thing_that_is_refused(): void
    {
        // The guard is on losing an owner, not on the role column changing.
        // A check that fired on any edit to an owner would refuse the one
        // action that makes the installation safer.
        $owner = $this->user(UserRole::Owner, 'sole@sanitube.test');
        $admin = $this->user(UserRole::Admin, 'admin@sanitube.test');

        $this->users()->changeRole($owner, $admin, UserRole::Owner);

        $this->assertSame(UserRole::Owner, $admin->fresh()?->role);
        $this->assertSame(2, $this->activeOwners());
    }

    #[Test]
    public function reactivating_an_owner_is_never_the_thing_that_is_refused(): void
    {
        $owner = $this->user(UserRole::Owner, 'sole@sanitube.test');
        $retired = $this->user(UserRole::Owner, 'retired@sanitube.test');

        $users = $this->users();
        $users->setActive($owner, $retired, false);

        $users->setActive($owner, $retired, true);

        $this->assertTrue($retired->fresh()?->is_active);
        $this->assertSame(2, $this->activeOwners());
    }

    #[Test]
    public function a_sole_owner_survives_every_attempt_to_remove_them(): void
    {
        // The surface rule, from the screen. An administrator has no route to
        // an owner at all, so none of these gets as far as the count.
        $owner = $this->user(UserRole::Owner, 'sole@sanitube.test');
        $admin = $this->user(UserRole::Admin, 'admin@sanitube.test');

        foreach ([['is_active' => false], ['role' => 'ADMIN'], ['role' => 'MEMBER']] as $attempt) {
            $this->actingAs($admin)
                ->patch('/users/'.$owner->uuid, $attempt)
                ->assertSessionHasErrors(['user' => 'OWNER_ONLY']);
        }

        $owner->refresh();

        $this->assertSame(UserRole::Owner, $owner->role);
        $this->assertTrue($owner->is_active);
    }

    #[Test]
    public function the_last_owner_has_nobody_left_who_may_ask(): void
    {
        // Stated from the screen, because it is the shape an operator meets:
        // with one owner left, the only account permitted to demote them is
        // their own, and that is refused for being one's own judge.
        $owner = $this->user(UserRole::Owner, 'sole@sanitube.test');

        $this->actingAs($owner)
            ->patch('/users/'.$owner->uuid, ['role' => 'ADMIN'])
            ->assertSessionHasErrors(['user' => 'NOT_YOURSELF']);

        $this->assertSame(UserRole::Owner, $owner->fresh()?->role);
        $this->assertSame(1, $this->activeOwners());
    }

    // --------------------------------------------- owners are owners' business

    #[Test]
    public function an_administrator_cannot_promote_anybody_to_owner(): void
    {
        // The whole point of the boundary. An admin who could do this would
        // *be* an owner, and the distinction would be decoration.
        $admin = $this->user(UserRole::Admin, 'admin@sanitube.test');
        $member = $this->user(UserRole::Member, 'member@sanitube.test');

        $this->actingAs($admin)
            ->patch('/users/'.$member->uuid, ['role' => 'OWNER'])
            ->assertSessionHasErrors(['user' => 'OWNER_ONLY']);

        $this->assertSame(UserRole::Member, $member->fresh()?->role);
    }

    #[Test]
    public function an_administrator_cannot_promote_themselves(): void
    {
        $admin = $this->user(UserRole::Admin, 'admin@sanitube.test');

        $this->actingAs($admin)
            ->patch('/users/'.$admin->uuid, ['role' => 'OWNER'])
            ->assertSessionHasErrors(['user' => 'NOT_YOURSELF']);

        $this->assertSame(UserRole::Admin, $admin->fresh()?->role);
    }

    #[Test]
    public function an_administrator_cannot_create_an_owner(): void
    {
        $this->actingAs($this->user(UserRole::Admin, 'admin@sanitube.test'))
            ->post('/users', [
                'name' => 'A New Owner',
                'email' => 'new.owner@sanitube.test',
                'role' => 'OWNER',
                'password' => 'a-long-enough-passphrase',
            ])
            ->assertSessionHasErrors(['user' => 'OWNER_ONLY']);

        $this->assertDatabaseMissing('users', ['email' => 'new.owner@sanitube.test']);
    }

    #[Test]
    public function an_owner_may_make_another_owner(): void
    {
        $this->actingAs($this->user(UserRole::Owner, 'owner@sanitube.test'))
            ->post('/users', [
                'name' => 'Co Owner',
                'email' => 'co.owner@sanitube.test',
                'role' => 'OWNER',
                'password' => 'a-long-enough-passphrase',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            UserRole::Owner,
            User::query()->where('email', 'co.owner@sanitube.test')->firstOrFail()->role,
        );
    }

    // ------------------------------------------------------ nobody's own judge

    #[Test]
    public function nobody_deactivates_their_own_account(): void
    {
        // Silent when it goes wrong: the screen reloads, the session is still
        // valid, and nothing looks broken until the next sign-in.
        $admin = $this->user(UserRole::Admin, 'admin@sanitube.test');

        $this->actingAs($admin)
            ->patch('/users/'.$admin->uuid, ['is_active' => false])
            ->assertSessionHasErrors(['user' => 'NOT_YOURSELF']);

        $this->assertTrue($admin->fresh()?->is_active);
    }

    // ------------------------------------------------------------ the ordinary

    #[Test]
    public function an_administrator_creates_a_member_and_the_password_is_hashed(): void
    {
        $this->actingAs($this->user(UserRole::Admin, 'admin@sanitube.test'))
            ->post('/users', [
                'name' => 'A Member',
                'email' => 'member@sanitube.test',
                'role' => 'MEMBER',
                'password' => 'a-long-enough-passphrase',
            ])
            ->assertSessionHasNoErrors();

        $created = User::query()->where('email', 'member@sanitube.test')->firstOrFail();

        $this->assertSame(UserRole::Member, $created->role);
        $this->assertTrue($created->is_active);

        // Never in the clear, whatever a future model cast does.
        $this->assertNotSame('a-long-enough-passphrase', $created->password);
        $this->assertTrue(password_verify('a-long-enough-passphrase', $created->password));
    }

    #[Test]
    public function a_short_password_is_refused(): void
    {
        $this->actingAs($this->user(UserRole::Admin, 'admin@sanitube.test'))
            ->post('/users', [
                'name' => 'A Member',
                'email' => 'member@sanitube.test',
                'role' => 'MEMBER',
                'password' => 'short',
            ])
            ->assertSessionHasErrors('password');
    }

    #[Test]
    public function an_address_belongs_to_one_account(): void
    {
        $this->user(UserRole::Member, 'taken@sanitube.test');

        $this->actingAs($this->user(UserRole::Admin, 'admin@sanitube.test'))
            ->post('/users', [
                'name' => 'Somebody Else',
                'email' => 'taken@sanitube.test',
                'role' => 'MEMBER',
                'password' => 'a-long-enough-passphrase',
            ])
            ->assertSessionHasErrors();
    }

    #[Test]
    public function every_change_names_a_person_in_the_audit_log(): void
    {
        $owner = $this->user(UserRole::Owner, 'owner@sanitube.test');
        $member = $this->user(UserRole::Member, 'member@sanitube.test');

        $this->actingAs($owner)->patch('/users/'.$member->uuid, ['role' => 'ADMIN']);
        $this->actingAs($owner)->patch('/users/'.$member->uuid, ['is_active' => false]);
        $this->actingAs($owner)->patch('/users/'.$member->uuid, ['is_active' => true]);

        foreach ([
            AuditAction::UserRoleChanged,
            AuditAction::UserDeactivated,
            AuditAction::UserReactivated,
        ] as $action) {
            $event = AuditEvent::query()->where('action', $action->value)->first();

            $this->assertNotNull($event, sprintf('[%s] was not recorded.', $action->value));
            $this->assertSame($member->uuid, $event->subject_uuid);
            $this->assertSame($owner->id, $event->actor_id);
        }
    }

    // ----------------------------------------------------- who may see this

    #[Test]
    public function a_member_cannot_reach_the_screen_or_change_anybody(): void
    {
        $member = $this->user(UserRole::Member, 'member@sanitube.test');
        $other = $this->user(UserRole::Member, 'other@sanitube.test');

        $this->actingAs($member)->get('/users')->assertForbidden();
        $this->actingAs($member)->patch('/users/'.$other->uuid, ['role' => 'ADMIN'])->assertForbidden();
        $this->actingAs($member)
            ->post('/users', [
                'name' => 'X',
                'email' => 'x@sanitube.test',
                'role' => 'ADMIN',
                'password' => 'a-long-enough-passphrase',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function a_stranger_reaches_nothing(): void
    {
        $this->get('/users')->assertRedirect('/login');
    }

    #[Test]
    public function the_screen_never_publishes_a_password_hash(): void
    {
        $owner = $this->user(UserRole::Owner, 'owner@sanitube.test');

        $body = (string) $this->actingAs($owner)->get('/users')->getContent();

        // A hash on a screenshot is a hash somebody can work on offline.
        $this->assertStringNotContainsString($owner->password, $body);
        $this->assertStringNotContainsString('$2y$', $body);
    }

    private function user(UserRole $role, string $email): User
    {
        return User::factory()->create(['role' => $role, 'email' => $email, 'is_active' => true]);
    }

    /**
     * The same person, as a request that started a moment ago still sees them.
     *
     * A second model instance rather than the same object: a concurrent
     * request holds a row it read at its own start, and reusing the instance
     * the other call just wrote through would test nothing but PHP references.
     */
    private function asItsOwnRequestSeesThem(User $user): User
    {
        return User::query()->findOrFail($user->getKey());
    }

    private function users(): ManageUsers
    {
        return $this->app->make(ManageUsers::class);
    }

    private function activeOwners(): int
    {
        return User::query()
            ->where('role', UserRole::Owner->value)
            ->where('is_active', true)
            ->count();
    }

    /**
     * @param  callable():mixed  $attempt
     */
    private function refusal(callable $attempt): UserAdministrationException
    {
        try {
            $attempt();
        } catch (UserAdministrationException $refusal) {
            return $refusal;
        }

        $this->fail('The change was allowed, and it should not have been.');
    }
}
