<?php

declare(strict_types=1);

namespace SaniTube\Audit\Services;

use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Enums\AuditActorKind;
use SaniTube\Audit\Enums\AuditOutcome;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Audit\Support\Redaction;
use Throwable;

/**
 * The only way a line gets into the audit log.
 *
 * **The actor is resolved here, never passed in.** A call site that could name
 * the actor could name somebody else, and a log whose attribution is an
 * argument is a log that can be made to lie by the code it is watching. Who
 * acted is whoever the guard says is authenticated at this moment; when that
 * is nobody, the answer is `guest` or `system` and the affected person is
 * recorded as the *subject* instead. This is why a completed password reset
 * shows a guest acting on a user rather than the user acting on themselves —
 * which is also the truth: at that instant nobody had authenticated.
 *
 * **Failing to write must not fail the operation.** This is an operational log
 * and not a ledger; nothing is derived from it, so refusing a release because
 * the audit table is unreachable would trade a real capability for a record of
 * capabilities. But a dropped line is not nothing either — an attacker who can
 * break the log should not thereby erase their trail — so a failed write is
 * re-emitted through the application log with the same fields. Whatever an
 * installation ships its logs to still sees it.
 *
 * That trade is only defensible because of what this table is not. If anything
 * ever computes a figure from `audit_events`, this class has to become
 * transactional first.
 */
final readonly class RecordAuditEvent
{
    public function __construct(
        private Redaction $redaction,
        private AuthFactory $auth,
        private Application $app,
    ) {}

    /**
     * Record something that happened.
     *
     * @param  string|null  $subjectUuid  the affected object's public uuid; null when the subject is the installation itself
     * @param  string|null  $reason  a machine-readable refusal code, never a sentence
     * @param  array<array-key, mixed>  $context  scrubbed before it is stored — see {@see Redaction}
     */
    public function record(
        AuditAction $action,
        AuditOutcome $outcome = AuditOutcome::Succeeded,
        ?string $subjectUuid = null,
        ?string $reason = null,
        array $context = [],
    ): ?AuditEvent {
        $actor = $this->actor();
        $origin = $this->origin();

        try {
            return AuditEvent::query()->create([
                'action' => $action,
                'subject' => $action->subject(),
                // Clamped, because a subject uuid is sometimes a value from a
                // URL — the failed-jobs actions identify their subject that
                // way — and a caller-supplied string longer than the column is
                // a write that fails on a strict engine and silently succeeds
                // on a lax one. Neither is a way to lose an audit line.
                'subject_uuid' => $this->clamp($subjectUuid, 36),
                'outcome' => $outcome,
                'reason' => $reason,
                'context' => $this->redaction->scrub($context) ?: null,
                'occurred_at' => now(),
                'actor_kind' => $actor['kind'],
                'actor_id' => $actor['id'],
                'actor_label' => $actor['label'],
                'actor_role' => $actor['role'],
                'ip_address' => $origin['ip_address'],
                'user_agent' => $origin['user_agent'],
            ]);
        } catch (Throwable $exception) {
            $this->fallBackToTheApplicationLog($action, $outcome, $subjectUuid, $reason, $exception);

            return null;
        }
    }

    /**
     * Record a refusal, with the code the domain already produced.
     *
     * @param  array<array-key, mixed>  $context
     */
    public function refused(
        AuditAction $action,
        string $reason,
        ?string $subjectUuid = null,
        array $context = [],
    ): ?AuditEvent {
        return $this->record($action, AuditOutcome::Refused, $subjectUuid, $reason, $context);
    }

    /**
     * Who is acting, as the guard sees it.
     *
     * @return array{kind: AuditActorKind, id: int|null, label: string|null, role: string|null}
     */
    private function actor(): array
    {
        $user = $this->auth->guard()->user();

        if ($user instanceof User) {
            return [
                'kind' => AuditActorKind::User,
                'id' => $user->id,
                // Snapshots, so a line stays readable after a rename and a
                // year of history does not join the users table. The name and
                // the role only: an audit log outlives the account and is
                // copied into every backup, and an email address there is a
                // liability that a name is not.
                'label' => $this->clamp($user->name, 120),
                'role' => $user->role->value,
            ];
        }

        // Nobody is signed in, so the question becomes how the action
        // arrived. Over a route means a person the platform has not
        // identified — a failed sign-in, a password reset — and that is a
        // guest. Anything else is a worker, the scheduler or a command.
        //
        // Deliberately not `runningInConsole()`. That asks about the process,
        // not the action: it is true for the whole test suite, and it would
        // have recorded every unauthenticated request in CI as the platform
        // acting on itself.
        $kind = $this->arrivedOverHttp() ? AuditActorKind::Guest : AuditActorKind::System;

        return ['kind' => $kind, 'id' => null, 'label' => null, 'role' => null];
    }

    /**
     * Whether this action arrived through a matched route.
     *
     * The question the actor and the origin both actually need answered. A
     * console command has a request object — Laravel binds one either way —
     * but nothing routed it.
     */
    private function arrivedOverHttp(): bool
    {
        if (! $this->app->bound(Request::class)) {
            return false;
        }

        return $this->app->make(Request::class)->route() !== null;
    }

    /**
     * Where it came from.
     *
     * Absent for console work, where nothing routed the action and inventing
     * `127.0.0.1` would say something untrue about how it reached the
     * platform.
     *
     * @return array{ip_address: string|null, user_agent: string|null}
     */
    private function origin(): array
    {
        if (! $this->arrivedOverHttp()) {
            return ['ip_address' => null, 'user_agent' => null];
        }

        $request = $this->app->make(Request::class);

        return [
            'ip_address' => $this->clamp($request->ip(), 45),
            // Caller-controlled text, stored and never executed; the column is
            // the bound rather than the caller's restraint.
            'user_agent' => $this->clamp($request->userAgent(), 255),
        ];
    }

    /**
     * The line still gets out, even when the table will not take it.
     */
    private function fallBackToTheApplicationLog(
        AuditAction $action,
        AuditOutcome $outcome,
        ?string $subjectUuid,
        ?string $reason,
        Throwable $exception,
    ): void {
        // Deliberately without the context: it was never scrubbed into a row,
        // and the point here is that the *fact* survives, not the detail.
        Log::error('Audit event could not be recorded.', [
            'action' => $action->value,
            'subject' => $action->subject()->value,
            // Clamped, because a subject uuid is sometimes a value from a
            // URL — the failed-jobs actions identify their subject that way —
            // and a caller-supplied string longer than the column is a write
            // that fails on a strict engine and silently succeeds on a lax
            // one. Neither is a way to lose an audit line.
            'subject_uuid' => $this->clamp($subjectUuid, 36),
            'outcome' => $outcome->value,
            'reason' => $reason,
            'failure' => $exception::class,
        ]);
    }

    private function clamp(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
