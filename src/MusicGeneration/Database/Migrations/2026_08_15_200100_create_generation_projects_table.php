<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A generation campaign.
 *
 * "Forty ambient instrumentals for the autumn catalogue" is a unit of intent,
 * and stating the style, language and target once is the difference between a
 * campaign and forty unrelated rows. The defaults are exactly that — defaults;
 * an individual generation may override any of them.
 *
 * `target_track_count` is nullable and is never enforced. It records what
 * somebody set out to make, so that "we wanted forty and got thirty-one" is a
 * question the platform can answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_projects', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->string('name');
            $table->string('status', 32);

            $table->unsignedInteger('target_track_count')->nullable();

            $table->string('default_language', 16)->nullable();
            $table->string('default_genre', 64)->nullable();
            $table->text('default_style_prompt')->nullable();

            // Identity does not exist yet. Declared now because backfilling
            // provenance onto campaigns already run is guesswork.
            $table->foreignId('created_by')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at'], 'generation_projects_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_projects');
    }
};
