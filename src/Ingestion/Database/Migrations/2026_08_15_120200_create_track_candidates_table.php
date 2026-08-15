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
 * `metadata` is a JSON column and is deliberately not indexed. It holds the
 * incidental — tags read out of a file, notes from an analyser — and anything
 * the platform needs to filter or enforce on belongs in a column instead. A
 * business rule that lives in a JSON blob is a business rule no database can
 * help you keep.
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

            $table->json('metadata')->nullable();

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
