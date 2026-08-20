<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who is currently submitting this generation to its provider.
 *
 * `provider_job_id` already makes a *completed* submission idempotent: once the
 * provider has answered, a redelivered job sees the identifier and stops. What
 * it cannot do is make an *in-flight* submission exclusive, because the column
 * it guards on is null for the entire duration of the call it is meant to
 * guard. Two workers handed the same job both read null, both call the
 * provider, and the installation pays twice for one generation — the second
 * job identifier belonging to a row nobody is polling.
 *
 * This column closes that window with the claim PROD-002 uses for production
 * slots: a guarded `UPDATE` whose affected-row count decides the winner, atomic
 * on every engine in the matrix without a transaction or a lock.
 *
 * Nullable rather than defaulted, because null means something here — nobody
 * has ever tried to submit this — and a default timestamp would make every
 * fresh row look like a claim somebody abandoned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_generations', function (Blueprint $table): void {
            $table->timestamp('submission_claimed_at')->nullable()->after('provider_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('music_generations', function (Blueprint $table): void {
            $table->dropColumn('submission_claimed_at');
        });
    }
};
