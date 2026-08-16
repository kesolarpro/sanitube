<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Enums\AuditActorKind;
use SaniTube\Audit\Enums\AuditOutcome;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Identity\Enums\UserRole;
use Tests\TestCase;

/**
 * The operations that actually reach the log.
 *
 * A recorder nothing calls is a table with a good docblock. These tests go
 * through the real routes, because the question is not whether
 * `RecordAuditEvent` works — that is covered next door — but whether the
 * platform's write paths use it, and whether they record the *refusals* rather
 * than only the successes.
 *
 * Authentication carries most of them, for the reason AUTH-002 gives: it is
 * the only surface reachable without being signed in, so it is the one an
 * administrator most needs a history of.
 */
final class AuditedOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        RateLimiter::clear('login|owner@example.test|127.0.0.1');
        RateLimiter::clear('password-reset|owner@example.test|127.0.0.1');
    }

    // ------------------------------------------------------ authentication

    #[Test]
    public function signing_in_is_recorded_against_the_person_who_did_it(): void
    {
        $user = $this->user();

        $this->post('/login', ['email' => $user->email, 'password' => 'a-long-enough-passphrase'])
            ->assertRedirect();

        $event = $this->only(AuditAction::UserSignedIn);

        $this->assertSame(AuditOutcome::Succeeded, $event->outcome);
        $this->assertSame($user->uuid, $event->subject_uuid);
        $this->assertSame(AuditActorKind::User, $event->actor_kind);
    }

    #[Test]
    public function a_wrong_password_is_recorded_against_the_account_it_was_aimed_at(): void
    {
        // The uuid, not the submitted address: a run of failures against one
        // person is what an administrator needs to see, and a table full of
        // strings a stranger typed is not.
        $user = $this->user();

        $this->post('/login', ['email' => $user->email, 'password' => 'not-the-password'])
            ->assertSessionHasErrors('email');

        $event = $this->only(AuditAction::UserSignInFailed);

        $this->assertSame(AuditOutcome::Refused, $event->outcome);
        $this->assertSame('INVALID_CREDENTIALS', $event->reason);
        $this->assertSame($user->uuid, $event->subject_uuid);
        $this->assertSame(AuditActorKind::Guest, $event->actor_kind);
    }

    #[Test]
    public function the_log_tells_the_three_refusals_apart_when_the_response_will_not(): void
    {
        // The response cannot distinguish them without becoming an enumeration
        // oracle. The log is behind the administer role, so it can — and the
        // difference between "wrong password" and "this account was
        // deactivated and somebody is still trying" is the whole value.
        $user = $this->user();
        $user->forceFill(['is_active' => false])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'a-long-enough-passphrase'])
            ->assertSessionHasErrors('email');

        $event = $this->only(AuditAction::UserSignInFailed);

        $this->assertSame('ACCOUNT_INACTIVE', $event->reason);
        $this->assertSame($user->uuid, $event->subject_uuid);
    }

    #[Test]
    public function an_unknown_address_is_recorded_without_being_stored(): void
    {
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'whatever-it-was'])
            ->assertSessionHasErrors('email');

        $event = $this->only(AuditAction::UserSignInFailed);

        // There is a line — somebody is trying addresses from this host — and
        // it names no account, because there is none to name.
        $this->assertNull($event->subject_uuid);

        foreach ((array) $event->getAttributes() as $column => $value) {
            $this->assertStringNotContainsString(
                'nobody@example.test',
                (string) $value,
                sprintf('[%s] stored an address somebody else chose.', $column),
            );
        }
    }

    #[Test]
    public function signing_out_names_the_person_rather_than_a_guest(): void
    {
        // Recorded before the logout, while the guard can still say who this
        // is. Afterwards it would be attributed to nobody.
        $user = $this->user();

        $this->actingAs($user)->post('/logout')->assertRedirect();

        $event = $this->only(AuditAction::UserSignedOut);

        $this->assertSame($user->uuid, $event->subject_uuid);
        $this->assertSame(AuditActorKind::User, $event->actor_kind);
        $this->assertSame($user->id, $event->actor_id);
    }

    // ------------------------------------------------------ password reset

    #[Test]
    public function a_reset_request_for_a_deactivated_account_is_recorded_as_such(): void
    {
        // Silence towards the browser is not silence towards the log. A stream
        // of resets aimed at somebody removed last week looks nothing like a
        // stranger guessing addresses, and only the log can say which it is.
        $user = $this->user();
        $user->forceFill(['is_active' => false])->save();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

        $event = $this->only(AuditAction::PasswordResetRequested);

        $this->assertSame(AuditOutcome::Refused, $event->outcome);
        $this->assertSame('ACCOUNT_INACTIVE', $event->reason);
        $this->assertSame($user->uuid, $event->subject_uuid);
    }

    #[Test]
    public function completing_a_reset_is_recorded_and_the_token_is_not(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertRedirect(route('login'));

        $event = $this->only(AuditAction::PasswordResetCompleted);

        $this->assertSame(AuditOutcome::Succeeded, $event->outcome);
        $this->assertSame($user->uuid, $event->subject_uuid);

        // Neither the token nor the new password, in any column, in any form.
        foreach (AuditEvent::query()->get() as $recorded) {
            foreach ((array) $recorded->getAttributes() as $column => $value) {
                $this->assertStringNotContainsString($token, (string) $value, sprintf('[%s] holds the reset token.', $column));
                $this->assertStringNotContainsString('a-brand-new-passphrase', (string) $value, sprintf('[%s] holds the new password.', $column));
            }
        }
    }

    // -------------------------------------------------------- failed jobs

    #[Test]
    public function refusing_to_retry_a_job_is_recorded(): void
    {
        // The most valuable line on this screen. `FAILED_JOB_NOT_RETRYABLE`
        // eleven times in a minute is somebody working through the list trying
        // to get a submission to run again.
        $this->actingAs($this->user());

        $this->post('/system/jobs/failed/a-uuid-that-is-not-there/retry')
            ->assertSessionHasErrors('jobs');

        $event = $this->only(AuditAction::FailedJobRetried);

        $this->assertSame(AuditOutcome::Refused, $event->outcome);
        $this->assertSame('FAILED_JOB_GONE', $event->reason);
        $this->assertSame('a-uuid-that-is-not-there', $event->subject_uuid);
    }

    #[Test]
    public function a_member_who_cannot_reach_the_screen_leaves_no_line(): void
    {
        // The middleware refuses before the controller runs, so there is
        // nothing to record — and recording it anyway would mean the audit log
        // grew whenever somebody mistyped a URL.
        $member = $this->user();
        $member->forceFill(['role' => UserRole::Member])->save();

        $this->actingAs($member)
            ->post('/system/jobs/failed/a-uuid/retry')
            ->assertForbidden();

        $this->assertSame(0, AuditEvent::query()->where('action', AuditAction::FailedJobRetried->value)->count());
    }

    // ------------------------------------------------------------- reading

    #[Test]
    public function reading_the_log_is_not_itself_an_event(): void
    {
        // A log that records its own readers grows by being looked at.
        $this->actingAs($this->user())->get('/system/audit')->assertOk();

        $this->assertSame(0, AuditEvent::query()->count());
    }

    #[Test]
    public function the_log_is_not_readable_without_the_administer_role(): void
    {
        $member = $this->user();
        $member->forceFill(['role' => UserRole::Member])->save();

        $this->actingAs($member)->get('/system/audit')->assertForbidden();
    }

    #[Test]
    public function an_unrecognised_filter_is_refused_rather_than_becoming_a_scan(): void
    {
        // `AuditAction::from()` on an unrecognised string is a ValueError and a
        // 500; the request rejects it first.
        $this->actingAs($this->user())
            ->get('/system/audit?action=whatever-i-typed')
            ->assertStatus(422);
    }

    #[Test]
    public function the_screen_can_be_asked_what_has_ever_been_done_to_one_object(): void
    {
        // The question this filter exists for. Everything ever done to one
        // release, one delivery, one account — matched exactly, so a partial
        // uuid cannot answer about somebody else's.
        $user = $this->user();
        $this->actingAs($user);

        $this->post('/system/jobs/failed/'.Str::uuid7()->toString().'/retry');

        $this->get('/system/audit?subject_uuid='.$user->uuid)->assertOk();
    }

    #[Test]
    public function a_subject_uuid_longer_than_the_column_does_not_lose_the_line(): void
    {
        // Reachable: the failed-jobs actions take their subject straight from
        // the URL, so this string is whatever somebody typed. A write that
        // fails on a strict engine and truncates on a lax one is not a way to
        // lose an audit line.
        $this->actingAs($this->user());

        $this->post('/system/jobs/failed/'.str_repeat('x', 200).'/retry')
            ->assertSessionHasErrors('jobs');

        $event = $this->only(AuditAction::FailedJobRetried);

        $this->assertSame(str_repeat('x', 36), $event->subject_uuid);
    }

    // ------------------------------------------------------------ fixtures

    private function only(AuditAction $action): AuditEvent
    {
        $events = AuditEvent::query()->where('action', $action->value)->get();

        $this->assertCount(1, $events, sprintf('Expected exactly one [%s] event.', $action->value));

        return $events->first();
    }

    private function user(): User
    {
        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'a-long-enough-passphrase',
        ]);

        $user->forceFill(['role' => UserRole::Owner, 'is_active' => true])->save();

        return $user;
    }
}
