<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A proposal that a Track could exist.
 *
 * The whole point of this table is that it is *not* `tracks`. Importing 900
 * files must not create 900 entries in the catalogue, because the catalogue is
 * the source of truth and a source of truth cannot be assembled by a batch job
 * guessing at titles from filenames.
 *
 * `suggested_title` is named as a suggestion for the same reason. Nothing in
 * here is catalogue data until a human promotes it.
 *
 * `metadata` holds JSON and is deliberately not indexed. It carries the
 * incidental — tags read out of a file, notes from an analyser — and anything
 * the platform needs to filter or enforce on belongs in a column instead. A
 * business rule that lives in a JSON blob is a business rule no database can
 * help you keep.
 *
 * It is declared `longText`, not `json`, and that is a portability decision
 * rather than a stylistic one. `json` is not one type across the matrix: MySQL 8
 * has a native binary type with no character set, while on MariaDB `json` is an
 * *alias* for `longtext ... collate utf8mb4_bin` plus a `json_valid()` check.
 * The same migration therefore yields two different types whose comparison
 * semantics differ — on MariaDB, comparing this column against any `_ci` column
 * raises "illegal mix of collations"; on MySQL the same expression means JSON
 * equality. `longText` is the same column on all four engines. The document is
 * encoded and decoded by the model, which is where it was already interpreted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('track_candidates', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->string('source', 32);

            // A candidate without bytes is a wish. Restrict on delete for the
            // same reason as everywhere else: the asset outlives the workflow.
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();

            $table->string('original_filename');
            $table->string('suggested_title')->nullable();

            // What this might already be. Populated by duplicate detection,
            // never acted on automatically.
            $table->foreignId('matched_track_id')->nullable()->constrained('tracks')->nullOnDelete();
            $table->foreignId('matched_asset_id')->nullable()->constrained('assets')->nullOnDelete();

            $table->string('status', 32);

            $table->longText('metadata')->nullable();

            $table->string('failure_code', 32)->nullable();
            $table->text('failure_message')->nullable();

            // Set once, by promotion. Its presence is what makes a second
            // promotion refusable.
            $table->foreignId('promoted_track_id')->nullable()->constrained('tracks')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'created_at'], 'track_candidates_status_created_index');
            $table->index('asset_id', 'track_candidates_asset_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('track_candidates');
    }
};
