<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracks', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->foreignId('composition_id')->nullable()->constrained()->restrictOnDelete();

            // The canonical master. This foreign key is the only place a
            // track's master is recorded — asset_links may not express it.
            $table->foreignId('master_asset_id')->nullable()->constrained('assets')->restrictOnDelete();

            $table->string('title');
            $table->string('version_title')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('language_code', 16);
            $table->boolean('is_instrumental')->default(false);
            $table->boolean('is_explicit')->default(false);
            $table->unsignedSmallInteger('bpm')->nullable();
            $table->string('musical_key', 16)->nullable();
            $table->string('genre_primary', 64)->nullable();
            $table->string('genre_secondary', 64)->nullable();
            $table->unsignedSmallInteger('recording_year')->nullable();
            $table->string('p_line')->nullable();

            $table->string('status', 32);
            $table->string('source', 32);

            $table->timestamps();
            $table->softDeletes();

            // No primary_artist_id. Track artists live in track_artist, which
            // is the single source of truth; a denormalised column here would
            // be a second one.
            $table->index('title');
            $table->index(['status', 'id']);
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracks');
    }
};
