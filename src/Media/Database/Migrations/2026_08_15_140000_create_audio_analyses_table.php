<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Technical analysis, kept apart from the asset it describes.
 *
 * `assets` holds what an asset *is* — where the bytes are, what they hash to,
 * whether they verified. Those are integrity properties and they are frozen
 * once stored. Analysis is a different kind of fact: it is an opinion produced
 * by a tool at a version, it can be produced again by a better tool, and it
 * can legitimately disagree with an earlier run.
 *
 * Putting both in one table would mean either freezing analysis with the
 * integrity fields, or unfreezing the integrity fields to let analysis change.
 * Neither is acceptable, so they are separate rows.
 *
 * The unique key is `(asset_id, analyzer, analyzer_version)`. Re-running the
 * same analyser at the same version is idempotent; running a *newer* version
 * adds a row beside the old one rather than overwriting it, so what an earlier
 * version concluded remains readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_analyses', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            // Restrict: the analysis describes these bytes and is meaningless
            // without them, but deleting an asset should be a deliberate act
            // that fails loudly while anything still refers to it.
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();

            $table->string('analyzer', 64);
            $table->string('analyzer_version', 32);

            $table->boolean('succeeded');

            // Every measurement is nullable. A container that does not record
            // bit depth is not a broken file, and a zero would be a number
            // nothing measured.
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('codec', 64)->nullable();
            $table->string('container', 64)->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedSmallInteger('bit_depth')->nullable();
            $table->unsignedSmallInteger('channels')->nullable();
            $table->unsignedInteger('bitrate')->nullable();

            // Decimal rather than float: loudness is compared and reported,
            // and a value that changes when it round-trips is a value nobody
            // can reconcile against a delivery specification.
            $table->decimal('loudness_lufs', 6, 2)->nullable();
            $table->decimal('peak_dbfs', 6, 2)->nullable();

            // The analyser's own output, for the questions nobody has asked
            // yet. Not indexed, and nothing the platform enforces lives here.
            //
            // `longText` rather than `json`: MariaDB's `json` is an alias for
            // `longtext collate utf8mb4_bin`, MySQL 8's is a native binary
            // type, and the divergence is real enough that the platform stores
            // documents as text everywhere and decodes them in PHP.
            $table->longText('raw')->nullable();

            $table->text('failure_message')->nullable();

            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'analyzer', 'analyzer_version'], 'audio_analyses_asset_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_analyses');
    }
};
