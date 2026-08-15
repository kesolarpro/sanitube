<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a reviewer decided, and when.
 *
 * ING-001 could record that a candidate existed; it had nothing to say about a
 * person having looked at it. Promotion and rejection are both *decisions*, and
 * a decision with no record of who made it or why is indistinguishable from
 * something the platform did on its own.
 *
 * The unique index on `promoted_track_id` is the load-bearing part. A track is
 * promoted from exactly one candidate, and saying so in the schema is what
 * stops two concurrent reviewers claiming the same track — NULLs are distinct
 * inside a unique index on every engine in the matrix, so any number of
 * unpromoted candidates coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('track_candidates', function (Blueprint $table): void {
            $table->timestamp('reviewed_at')->nullable()->after('promoted_track_id');

            // Nullable because Identity does not exist yet, and declared now
            // for the same reason `created_by` was: backfilling provenance onto
            // decisions already taken is guesswork.
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at');

            // Why a candidate was rejected, or why a reviewer overruled the
            // machine. An override with no stated reason is a fact nobody can
            // audit six months later.
            $table->text('review_note')->nullable()->after('reviewed_by');

            $table->unique('promoted_track_id', 'track_candidates_promoted_track_unique');
        });
    }

    public function down(): void
    {
        Schema::table('track_candidates', function (Blueprint $table): void {
            $table->dropUnique('track_candidates_promoted_track_unique');
            $table->dropColumn(['reviewed_at', 'reviewed_by', 'review_note']);
        });
    }
};
