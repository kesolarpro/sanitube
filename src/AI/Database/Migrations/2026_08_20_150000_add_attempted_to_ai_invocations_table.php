<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a request actually left this server.
 *
 * The ledger deliberately records calls that never happened — an installation
 * with no key, a request the quota refused — because "why did nothing get
 * suggested last night" is answerable only if the non-events are written down.
 *
 * That makes `succeeded` the wrong column to count spending by, in both
 * directions. A refused-before-sending row costs nothing and would inflate a
 * quota; a vendor call that returned a 500 costs a request and would be missed.
 * So attempt and outcome are two columns, and the quota counts attempts.
 *
 * **Defaults to true, and existing rows are backfilled to true.** Every row
 * written before this column existed was an attempt or an unavailable-provider
 * refusal, and the conservative reading of an unknown row is that it cost
 * something. A quota that under-counts history is a quota that lets an
 * installation past a ceiling it had already reached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_invocations', function (Blueprint $table): void {
            $table->boolean('attempted')->default(true)->after('succeeded');
        });

        // The two questions a quota asks, and neither is served by the
        // existing indexes: how many attempts has this provider made since a
        // moment, and how did the most recent ones end.
        Schema::table('ai_invocations', function (Blueprint $table): void {
            $table->index(['attempted', 'created_at'], 'ai_invocations_attempted_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_invocations', function (Blueprint $table): void {
            $table->dropIndex('ai_invocations_attempted_created_index');
            $table->dropColumn('attempted');
        });
    }
};
