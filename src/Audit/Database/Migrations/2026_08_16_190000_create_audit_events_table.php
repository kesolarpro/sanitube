<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operational audit log.
 *
 * **Operational, not financial.** It records who did what, to which object,
 * when, and from where. It is not a ledger, nothing is derived from it, and no
 * figure anywhere in SaniTube is computed by reading it. That is what makes
 * best-effort writing acceptable here and would make it unacceptable in a
 * ledger — a fact worth stating in the schema, because the first person who
 * wants to total something will look at this table.
 *
 * **Append-only in intent and in shape.** There is no `updated_at`, because
 * nothing updates. The model refuses updates and deletes outright; the only
 * removal is the retention prune, which is itself audited.
 *
 * **`actor_id` is RESTRICT, and there is also `actor_label`.** The constraint
 * is the same argument DIST-001-H1 settled for `decided_by`: deleting a person
 * must not silently erase or orphan the record of what they did. The label is
 * a snapshot taken at write time so a line stays readable after a rename, and
 * so reading a year of history does not join the users table a thousand times.
 * It holds the display name and the role, never the email address — an audit
 * log is long-lived and widely copied, and a name is enough to know who.
 *
 * **`context` is longText with an array cast, never `json()`.** MariaDB's
 * `json` is an alias for `longtext` while MySQL 8's is a native binary type, so
 * one migration would yield two types with two comparison semantics
 * (ADR-0002). Nothing queries inside it, so nothing is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            // A value from AuditAction, stored as its string. Wide enough for
            // the longest `domain.subject.verb` with room to grow.
            $table->string('action', 80);

            // A short domain word from AuditSubject — never a class name.
            $table->string('subject', 40);

            // The subject's public uuid. Null for the actions whose subject is
            // the installation itself, which is why `subject` is not nullable:
            // "about the system" and "about nothing" are different answers.
            $table->string('subject_uuid', 36)->nullable();

            $table->string('outcome', 16);

            // The machine-readable refusal code the domain already produces.
            // Never a sentence: the English lives in the translations.
            $table->string('reason', 80)->nullable();

            $table->string('actor_kind', 16);

            // Restrict, deliberately. Cascade would let deleting a user delete
            // the record of what they did; null-on-delete would keep a line
            // that says a person acted and cannot say who, which looks
            // complete and is not.
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();

            // Snapshots. Name and role only — not the email address.
            $table->string('actor_label', 120)->nullable();
            $table->string('actor_role', 20)->nullable();

            // 45 characters holds an IPv6 address with an embedded IPv4 one.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Scrubbed by Redaction before it ever arrives here.
            $table->longText('context')->nullable();

            $table->timestamp('occurred_at');

            // The two questions this table is opened to answer: "what happened
            // recently" and "what has ever been done to this object".
            $table->index('occurred_at', 'audit_events_occurred_index');
            $table->index(['subject', 'subject_uuid'], 'audit_events_subject_index');
            $table->index('action', 'audit_events_action_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
