<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A body of work somebody intends to produce, and how much of it the platform
 * may do on its own.
 *
 * **`autonomy_mode` is on this table and on no other.** How much a machine may
 * do unattended is a decision about a body of work, not about a credit — the
 * same artist can appear on one plan a person drives by hand and another that
 * generates unattended, and a column on `artists` would make those the same
 * setting. `editorial_profiles` does not carry it either, for the same reason
 * in the other direction: an imprint's taste and its appetite for automation
 * are different questions.
 *
 * `target_track_count` is nullable and is **not** a limit the platform
 * enforces on its own; it is what somebody set out to make, so "we wanted forty
 * and got thirty-one" is answerable. What actually stops a plan is its status,
 * and reaching the target is one of the things that sets it.
 *
 * Timestamps state their defaults (ADR-0017), and the `longText` array columns
 * avoid `json()` (ADR-0002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_plans', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->string('name');
            $table->string('slug', 128)->unique();

            // What this plan sounds like is the profile's business, not this
            // table's. Restricted rather than cascaded: deleting an imprint out
            // from under a running plan would leave the plan with no idea what
            // it was producing.
            $table->foreignId('editorial_profile_id')->constrained('editorial_profiles')->restrictOnDelete();

            $table->string('autonomy_mode', 32);
            $table->string('status', 32);

            $table->unsignedInteger('target_track_count')->nullable();

            // How often the plan expects to produce something, in days. A
            // cadence rather than a schedule: a plan is a standing intention,
            // and the moment a slot actually runs is the slot's business.
            $table->unsignedSmallInteger('cadence_days')->nullable();

            $table->text('notes')->nullable();

            // Why the platform stopped, when the platform stopped it. Null
            // while running and while a person is the one who stopped it --
            // a person's reason belongs in the audit line that records their
            // act, not in a column the plan overwrites next time.
            $table->string('halted_reason', 64)->nullable();
            $table->timestamp('halted_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The two questions asked of this table: what should be running,
            // and what has stopped and needs somebody.
            $table->index(['status', 'created_at'], 'production_plans_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_plans');
    }
};
