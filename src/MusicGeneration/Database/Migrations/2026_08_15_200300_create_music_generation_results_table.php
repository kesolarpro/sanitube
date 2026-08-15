<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One audio variant a generation produced.
 *
 * A single request routinely returns several takes, and the platform keeps all
 * of them. Selecting one is a decision about which variant becomes a
 * candidate; it is not a decision to destroy the others, and a reviewer who
 * changes their mind an hour later must still be able to hear take two.
 *
 * `selected` is therefore a flag rather than a delete, and `asset_id` /
 * `candidate_id` are written only for the variant that was actually imported.
 * Their presence is what makes a second selection of the same result free
 * rather than a second asset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_generation_results', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->foreignId('generation_id')->constrained('music_generations')->cascadeOnDelete();

            $table->string('provider_result_id', 191);

            // Where the provider is currently serving the audio. A transfer
            // detail, not an address the catalogue may rely on: providers
            // expire these, and nothing is catalogue data until the Asset
            // Registry has hashed it.
            $table->string('source_reference', 1024)->nullable();

            $table->string('title')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->boolean('selected')->default(false);

            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained('track_candidates')->nullOnDelete();

            $table->longText('raw')->nullable();

            $table->timestamps();

            // A provider's result identifier is unique within its generation.
            // Re-fetching a job's results — which polling does on every pass —
            // must update the rows it already created rather than adding to
            // them.
            $table->unique(['generation_id', 'provider_result_id'], 'music_generation_results_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_generation_results');
    }
};
