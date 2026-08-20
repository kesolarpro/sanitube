<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an occasion actually turned into.
 *
 * PROD-002 opened slots and PROD-003 taught a worker to decide whether one was
 * warranted; then the chain stopped, deliberately, with the decision recorded
 * and nothing acting on it. Two columns are what let something act and still be
 * answerable afterwards.
 *
 * `music_generation_id` is the answer to "why does this track exist" read from
 * the other end: an occasion, a plan, a cadence somebody set. Nullable, because
 * most slots never reach a generation -- an inventory that found enough is the
 * ordinary outcome -- and `nullOnDelete` because a deleted generation must not
 * take the record of the occasion with it.
 *
 * `attempted_providers` is what stops a re-route from going back to the
 * supplier that just failed. It is a list rather than a count because the
 * question at three in the morning is *which* ones were tried, and a count
 * cannot answer it.
 *
 * `longText` with an array cast, never `json()`: ADR-0002. MariaDB's `json` is
 * an alias for `longtext` with a check constraint and the two engines disagree
 * about what may be indexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_slots', function (Blueprint $table): void {
            $table->foreignId('music_generation_id')
                ->nullable()
                ->after('intent')
                ->constrained('music_generations')
                ->nullOnDelete();

            $table->longText('attempted_providers')->nullable()->after('music_generation_id');
        });
    }

    public function down(): void
    {
        Schema::table('production_slots', function (Blueprint $table): void {
            // Named explicitly. The generated constraint name is derived from
            // table and column and is well inside 64 characters, but dropping
            // by column name is what works identically on SQLite, MySQL and
            // MariaDB -- see ADR-0017.
            $table->dropForeign(['music_generation_id']);
            $table->dropColumn(['music_generation_id', 'attempted_providers']);
        });
    }
};
