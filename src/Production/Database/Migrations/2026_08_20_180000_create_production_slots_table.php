<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One occasion on which a plan intends something to happen.
 *
 * **A slot is a standing invitation, not the work.** The scheduler opens slots
 * and does nothing else; whatever actually produces something claims one. That
 * separation is the whole design, and it is what makes the work idempotent,
 * traceable, cancelable and auditable rather than a cron entry that fires into
 * the dark.
 *
 * **`(production_plan_id, scheduled_for)` is unique, and that is the
 * idempotency key.** A scheduler that ran twice in one minute — because a cron
 * overlapped, because an operator pressed the button, because a deploy
 * restarted it — must produce one slot and not two. Enforcing it in the index
 * rather than by checking first is the difference between "usually once" and
 * "once": two processes can both find nothing and both insert, and only the
 * database can settle that.
 *
 * `scheduled_for` is a **date**, not a timestamp. A plan produces something
 * "every seven days", not "at 03:14 on the seventh day", and a time in this
 * column would make the unique key depend on when the scheduler happened to
 * run — which is exactly the thing it must not depend on.
 *
 * **One engine caveat, found while testing the race.** Laravel writes a `date`
 * cast through the connection's datetime format, so an Eloquent insert stores
 * `Y-m-d H:i:s`. MySQL and MariaDB coerce that to a DATE and two writers
 * disagreeing about the format still collide correctly. SQLite does not: its
 * typing is dynamic, the column holds whatever string it was given, and a
 * writer inserting a bare `Y-m-d` would sit alongside an Eloquent row for the
 * same day without tripping this key.
 *
 * Everything in this application writes these rows through Eloquent, so the key
 * holds. It is recorded because the failure mode is invisible — a raw insert in
 * a future seeder or import would silently create the duplicate this table
 * exists to prevent, and only on the engine the test suite runs fastest on.
 *
 * Timestamps state their defaults (ADR-0017).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_slots', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            // Cascaded rather than restricted, unlike the plan's own profile
            // reference: a slot has no meaning without its plan, and an
            // orphaned invitation to produce something for nothing is worse
            // than a gap.
            $table->foreignId('production_plan_id')->constrained('production_plans')->cascadeOnDelete();

            $table->date('scheduled_for');

            $table->string('status', 32);

            // What the slot is an invitation to do, in SaniTube's words. One
            // intent today; the column exists so a second does not need a
            // second table.
            $table->string('intent', 64);

            // Who or what took it, and when. `claimed_by` is a worker's own
            // name rather than a user: a slot is claimed by a process, and the
            // question after an incident is which one.
            $table->string('claimed_by', 128)->nullable();
            $table->timestamp('claimed_at')->nullable();

            // How it ended, as a code. Null while it is still open.
            $table->string('outcome_reason', 64)->nullable();
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            // The idempotency key. See the note above: this is enforced here
            // rather than by looking first, because two processes can both
            // look and both find nothing.
            $table->unique(['production_plan_id', 'scheduled_for'], 'production_slots_plan_date_unique');

            // What a worker asks for: the oldest thing still waiting.
            $table->index(['status', 'scheduled_for'], 'production_slots_status_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_slots');
    }
};
