<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was measured from an artwork file, kept apart from the asset itself.
 *
 * The same separation `audio_analyses` makes, for the same reason: `assets`
 * holds integrity properties that are frozen once stored, while a measurement
 * is an opinion produced by a tool at a version and can legitimately be
 * produced again by a better one.
 *
 * The unique key is `(asset_id, measurer, measurer_version)`. Re-measuring at
 * the same version is idempotent; a newer version adds a row beside the old
 * one, so what an earlier version concluded stays readable.
 *
 * **`succeeded` is a column rather than an inference from null dimensions.** A
 * file nobody could read and a file nobody has looked at are different states,
 * and a release validator has to tell them apart — one is a warning to go and
 * look, the other is an error about the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artwork_analyses', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            // Restrict: the measurement describes these bytes and is
            // meaningless without them.
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();

            $table->string('measurer', 64);
            $table->string('measurer_version', 32);

            $table->boolean('succeeded');

            // Nullable, because a failed measurement has none of them and a
            // zero would be a number nothing measured.
            $table->unsignedInteger('width_px')->nullable();
            $table->unsignedInteger('height_px')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();

            // Only formats that record these carry them. JPEG does; PNG does
            // not. Null means nothing was learned, which is different from
            // three channels and is never reported as RGB.
            $table->unsignedSmallInteger('channels')->nullable();
            $table->unsignedSmallInteger('bits')->nullable();

            // Non-nullable, so ADR-0017's rule is satisfied explicitly: every
            // non-nullable timestamp states its default.
            $table->timestamp('measured_at')->useCurrent();

            $table->timestamps();

            $table->unique(['asset_id', 'measurer', 'measurer_version'], 'artwork_analyses_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artwork_analyses');
    }
};
