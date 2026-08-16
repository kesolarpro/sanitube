<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Enums\AuditActorKind;
use SaniTube\Audit\Enums\AuditOutcome;
use SaniTube\Audit\Enums\AuditSubject;
use SaniTube\Audit\Exceptions\AuditEventIsImmutable;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Audit\Services\PruneAuditLog;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Audit\Support\Redaction;
use SaniTube\Identity\Enums\UserRole;
use Tests\TestCase;

/**
 * The operational audit log.
 *
 * Four claims carry these tests, and they are the four that would make the log
 * worthless if any of them were merely intended rather than enforced:
 *
 *   - **The actor is resolved, never passed.** A caller that could name the
 *     actor could name somebody else.
 *   - **Nothing can change or remove a line.** "Nothing updates audit rows" is
 *     a property of today's code; it has to be a property of the model.
 *   - **No secret shape survives the context.** Not because callers are
 *     careful, but because the filter refuses.
 *   - **A failed write does not fail the operation, and does not vanish.**
 */
final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ recording

    #[Test]
    public function an_event_records_what_happened_and_who_did_it(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->recorder()->record(AuditAction::ReleaseCreated, subjectUuid: 'a-release-uuid');

        $event = $this->latest();

        $this->assertSame(AuditAction::ReleaseCreated, $event->action);
        $this->assertSame(AuditSubject::Release, $event->subject);
        $this->assertSame('a-release-uuid', $event->subject_uuid);
        $this->assertSame(AuditOutcome::Succeeded, $event->outcome);
        $this->assertSame(AuditActorKind::User, $event->actor_kind);
        $this->assertSame($user->id, $event->actor_id);
    }

    #[Test]
    public function the_subject_kind_comes_from_the_action_and_not_from_the_caller(): void
    {
        // Two call sites recording the same action cannot disagree about what
        // the subject uuid refers to, because neither of them gets to say.
        $this->actingAs($this->user());

        $this->recorder()->record(AuditAction::FailedJobRetried, subjectUuid: 'a-job-uuid');

        $this->assertSame(AuditSubject::FailedJob, $this->latest()->subject);
    }

    #[Test]
    public function a_refusal_is_recorded_with_the_code_the_domain_produced(): void
    {
        // The refusals are the point. A log of everything that worked
        // describes a well-behaved installation and nothing else.
        $this->actingAs($this->user());

        $this->recorder()->refused(AuditAction::DeliverySubmitted, 'RELEASE_NOT_READY');

        $event = $this->latest();

        $this->assertSame(AuditOutcome::Refused, $event->outcome);
        $this->assertSame('RELEASE_NOT_READY', $event->reason);
    }

    #[Test]
    public function console_work_is_the_system_and_carries_no_address(): void
    {
        // The test runner is a console context. Inventing 127.0.0.1 here would
        // say something untrue about how the action reached the platform.
        $this->recorder()->record(AuditAction::BackupCreated);

        $event = $this->latest();

        $this->assertSame(AuditActorKind::System, $event->actor_kind);
        $this->assertNull($event->actor_id);
        $this->assertNull($event->ip_address);
    }

    // ------------------------------------------------------------- the actor

    #[Test]
    public function the_actor_label_is_a_snapshot_that_survives_a_rename(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        $this->recorder()->record(AuditAction::ReleaseCreated, subjectUuid: 'a-release-uuid');

        $user->forceFill(['name' => 'Somebody Else'])->save();

        // A line about somebody who has since been renamed should say who they
        // were when they did it. A join would quietly rewrite the past every
        // time a profile is edited.
        $this->assertSame('Owner', $this->latest()->actor_label);
    }

    #[Test]
    public function the_actor_snapshot_never_holds_an_email_address(): void
    {
        // The log outlives the account and is copied into every backup. A name
        // is enough to know who.
        $user = $this->user();
        $this->actingAs($user);

        $this->recorder()->record(AuditAction::ReleaseCreated, subjectUuid: 'a-release-uuid');

        $row = (array) $this->latest()->getAttributes();

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString(
                $user->email,
                (string) $value,
                sprintf('[%s] holds the actor’s email address.', $column),
            );
        }
    }

    #[Test]
    public function a_user_with_audit_history_cannot_be_deleted_out_from_under_it(): void
    {
        // RESTRICT, deliberately. Cascade would let deleting a user delete the
        // record of what they did; null-on-delete would keep a line that says
        // a person acted and cannot say who.
        $user = $this->user();
        $this->actingAs($user);

        $this->recorder()->record(AuditAction::ReleaseCreated, subjectUuid: 'a-release-uuid');

        $this->expectException(QueryException::class);

        $user->delete();
    }

    // ---------------------------------------------------------- immutability

    #[Test]
    public function an_event_cannot_be_changed(): void
    {
        $this->recorder()->record(AuditAction::BackupCreated);

        $this->expectException(AuditEventIsImmutable::class);

        $this->latest()->update(['reason' => 'something else entirely']);
    }

    #[Test]
    public function an_event_cannot_be_deleted_one_at_a_time(): void
    {
        $this->recorder()->record(AuditAction::BackupCreated);

        $this->expectException(AuditEventIsImmutable::class);

        $this->latest()->delete();
    }

    // ---------------------------------------------------------- the redaction

    #[Test]
    public function a_denylisted_key_is_redacted_whatever_it_holds(): void
    {
        $this->recorder()->record(AuditAction::BackupCreated, context: [
            'db_password' => 'hunter2',
            'reset_token' => 'abc',
            'api_key_name' => 'production',
            'x_signature' => 'deadbeef',
            // Not denied: derived from public data, and exactly what an
            // operator needs.
            'idempotency_key' => 'a-sha-256-of-public-things',
        ]);

        $context = (array) $this->latest()->context;

        foreach (['db_password', 'reset_token', 'api_key_name', 'x_signature'] as $key) {
            $this->assertSame(Redaction::PLACEHOLDER, $context[$key], sprintf('[%s] survived.', $key));
        }

        $this->assertSame('a-sha-256-of-public-things', $context['idempotency_key']);
    }

    #[Test]
    public function no_url_is_ever_stored_signed_or_otherwise(): void
    {
        // A signed URL is a bearer credential and a private object URL is a
        // location. Detecting "signed" specifically would mean keeping up with
        // every provider's signature parameter.
        $this->recorder()->record(AuditAction::AssetPreviewMinted, subjectUuid: 'an-asset-uuid', context: [
            'where' => 'https://bucket.example.test/masters/track.wav?X-Amz-Signature=abc',
            'plain' => 'https://example.test/',
        ]);

        $context = (array) $this->latest()->context;

        $this->assertSame(Redaction::PLACEHOLDER, $context['where']);
        $this->assertSame(Redaction::PLACEHOLDER, $context['plain']);
    }

    #[Test]
    public function a_credential_shaped_value_is_refused_under_any_key_name(): void
    {
        $this->recorder()->record(AuditAction::BackupCreated, context: [
            'note' => 'Bearer eyJhbGciOiJIUzI1NiJ9.payload.signature',
            'material' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----",
        ]);

        $context = (array) $this->latest()->context;

        $this->assertSame(Redaction::PLACEHOLDER, $context['note']);
        $this->assertSame(Redaction::PLACEHOLDER, $context['material']);
    }

    #[Test]
    public function a_long_value_is_discarded_rather_than_truncated(): void
    {
        // A truncated secret is still a secret with its first two hundred
        // characters intact.
        $secret = str_repeat('s', Redaction::MAX_VALUE_LENGTH + 1);

        $this->recorder()->record(AuditAction::BackupCreated, context: ['blob' => $secret]);

        $stored = (string) ((array) $this->latest()->context)['blob'];

        $this->assertSame(Redaction::PLACEHOLDER, $stored);
        $this->assertStringNotContainsString(substr($secret, 0, 20), $stored);
    }

    #[Test]
    public function the_context_is_bounded_so_an_audited_operation_cannot_fill_the_log(): void
    {
        $context = [];

        for ($i = 0; $i < Redaction::MAX_KEYS * 3; $i++) {
            $context['key_'.$i] = 'value';
        }

        $this->recorder()->record(AuditAction::BackupCreated, context: $context);

        $this->assertCount(Redaction::MAX_KEYS, (array) $this->latest()->context);
    }

    // ------------------------------------------------------- when it breaks

    #[Test]
    public function a_write_that_fails_does_not_fail_the_operation_and_does_not_vanish(): void
    {
        // An operational log, not a ledger: refusing a release because the
        // audit table is unreachable would trade a real capability for a
        // record of capabilities. But an attacker who can break the log must
        // not thereby erase their trail.
        $seen = [];

        Log::listen(function (MessageLogged $entry) use (&$seen): void {
            $seen[] = $entry;
        });

        $this->app['db']->statement('DROP TABLE audit_events');

        $recorded = $this->recorder()->record(AuditAction::DeliverySubmitted, subjectUuid: 'a-delivery-uuid');

        $this->assertNull($recorded, 'A write that could not happen must not be reported as one that did.');
        $this->assertCount(1, $seen);

        $entry = $seen[0];

        $this->assertSame('error', $entry->level);
        $this->assertSame('Audit event could not be recorded.', $entry->message);
        $this->assertSame(AuditAction::DeliverySubmitted->value, $entry->context['action']);
        $this->assertSame('a-delivery-uuid', $entry->context['subject_uuid']);

        // The context is deliberately absent: it never reached a row, and what
        // must survive here is the fact rather than the detail.
        $this->assertArrayNotHasKey('context', $entry->context);
    }

    // ------------------------------------------------------------- retention

    #[Test]
    public function pruning_removes_whole_periods_and_records_that_it_did(): void
    {
        $this->recorder()->record(AuditAction::BackupCreated);
        $this->latest()->newQuery()->update(['occurred_at' => now()->subDays(400)]);

        $removed = $this->app->make(PruneAuditLog::class)->handle(365);

        $this->assertSame(1, $removed);

        // The gap explains itself. Written before the delete, so a failure
        // halfway does not remove rows with nothing saying so.
        $event = $this->latest();

        $this->assertSame(AuditAction::AuditLogPruned, $event->action);
        $this->assertSame(365, ((array) $event->context)['retention_days']);
    }

    #[Test]
    public function a_retention_below_the_floor_is_raised_rather_than_honoured(): void
    {
        // Three days is not a retention policy, it is a way of turning the log
        // off.
        // Ten days old: inside the thirty-day floor, well outside the one day
        // the caller asked for. It survives only if the floor was applied.
        $this->recorder()->record(AuditAction::BackupCreated);
        $this->latest()->newQuery()->update(['occurred_at' => now()->subDays(10)]);

        $removed = $this->app->make(PruneAuditLog::class)->handle(1);

        $this->assertSame(0, $removed, 'A ten-day-old event was removed under a retention that should have been raised to thirty days.');
    }

    #[Test]
    public function pruning_nothing_says_nothing(): void
    {
        // A line every night saying nothing was removed is how a log stops
        // being read.
        $this->recorder()->record(AuditAction::BackupCreated);

        $this->app->make(PruneAuditLog::class)->handle(365);

        $this->assertSame(1, AuditEvent::query()->count());
        $this->assertSame(AuditAction::BackupCreated, $this->latest()->action);
    }

    #[Test]
    public function the_prune_command_reports_what_it_removed(): void
    {
        $this->recorder()->record(AuditAction::BackupCreated);
        $this->latest()->newQuery()->update(['occurred_at' => now()->subDays(400)]);

        $this->artisan('sanitube:audit:prune', ['--days' => 365])
            ->assertExitCode(0);

        $this->assertSame(AuditAction::AuditLogPruned, $this->latest()->action);
    }

    // ------------------------------------------------------------- fixtures

    private function recorder(): RecordAuditEvent
    {
        return $this->app->make(RecordAuditEvent::class);
    }

    private function latest(): AuditEvent
    {
        $event = AuditEvent::query()->orderByDesc('id')->first();

        $this->assertInstanceOf(AuditEvent::class, $event, 'No audit event was recorded.');

        return $event;
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
