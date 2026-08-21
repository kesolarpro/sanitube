<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How this generation was executed.
 *
 * Provenance, not routing. The branch that chose it is decided at submission
 * time from the provider's own interfaces; this column exists so the *row* can
 * answer for itself afterwards — "why does this generation have no provider job
 * identifier" is a question somebody asks long after the supplier that produced
 * it has been reconfigured or removed from the installation entirely.
 *
 * It is also what keeps a synchronous generation away from the poller: nothing
 * should ever ask a supplier for the status of a job that never existed.
 *
 * Nullable, and null on every row that predates GEN-007. Backfilling would mean
 * asserting how a past generation was executed, and every one of them was
 * asynchronous only because that was the only shape the platform had — which is
 * a fact about the code, not about the supplier. Null says "before this was
 * recorded", which is true.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('music_generations', function (Blueprint $table): void {
            $table->string('execution_mode', 32)->nullable()->after('provider_job_id');
        });
    }

    public function down(): void
    {
        Schema::table('music_generations', function (Blueprint $table): void {
            $table->dropColumn('execution_mode');
        });
    }
};
