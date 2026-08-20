<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a model proposed, and everything needed to judge it later.
 *
 * **The provenance columns are not bookkeeping.** A prompt later found to be
 * wrong has to be traceable to the suggestions it produced, and that is only
 * possible if the version, the provider, the model and *which piece of stored
 * evidence was read* were written down at the time. `prompt_version` and
 * `evidence_version` exist for exactly the question "which of these do we still
 * trust".
 *
 * **`longText` with an array cast, never `json()`.** ADR-0002: MariaDB's `json`
 * is an alias for `longtext` with a check constraint and MySQL's is a real
 * type, so the column differs across the four engines this platform supports
 * while `longText` is the same everywhere.
 *
 * **Timestamps state their defaults.** ADR-0017: MySQL and MariaDB give the
 * first `TIMESTAMP` an implicit `CURRENT_TIMESTAMP` and every later NOT NULL
 * one an implicit zero-date that strict mode rejects, so `suggested_at`
 * declares its own rather than inheriting one by column order.
 *
 * **`confidence_basis_points` is signed.** ADR-0017 again: an unsigned column
 * that anything ever subtracts from produces `BIGINT UNSIGNED value is out of
 * range` on MySQL, and a confidence is exactly the sort of number somebody
 * later diffs between two suggestions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metadata_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();

            // SaniTube's word for what was asked, never the vendor's endpoint.
            // A ledger grouped by `chat.completions` answers no question anyone
            // actually asks.
            $table->string('task', 64);

            $table->string('provider', 64);
            $table->string('model', 128)->nullable();
            $table->string('prompt_version', 32);

            // Which stored evidence produced this. Nullable because a future
            // task may read something other than a transcript, and a foreign
            // key that forced one would make this table about transcription.
            $table->foreignId('transcript_id')->nullable()->constrained('transcripts')->nullOnDelete();

            // The version of the evidence, copied at the time. A transcript
            // re-made by a newer provider version is different evidence, and
            // without this column nothing can tell whether a suggestion was
            // made from the transcript that is there now.
            $table->string('evidence_version', 64)->nullable();

            $table->string('suggested_title', 512)->nullable();
            $table->string('suggested_language', 16)->nullable();
            $table->longText('suggested_genres')->nullable();
            $table->longText('suggested_moods')->nullable();
            $table->longText('suggested_themes')->nullable();
            $table->longText('suggested_description')->nullable();

            $table->integer('confidence_basis_points')->nullable();
            $table->longText('rationale')->nullable();

            $table->string('status', 32)->index();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->timestamp('suggested_at')->useCurrent();
            $table->timestamps();

            // The queue is read by asset and by what is still waiting for a
            // person, and both are the common query.
            $table->index(['asset_id', 'task']);
            $table->index(['status', 'suggested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_suggestions');
    }
};
