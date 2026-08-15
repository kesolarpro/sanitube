<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_tracks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('release_id')->constrained()->cascadeOnDelete();

            // Restrict: a track can appear on several releases, so removing it
            // from the catalogue while a release still references it must fail
            // loudly rather than leave a gap in a tracklist.
            $table->foreignId('track_id')->constrained()->restrictOnDelete();

            $table->unsignedInteger('disc_number')->default(1);
            $table->unsignedInteger('track_number');
            $table->boolean('is_focus_track')->default(false);

            $table->timestamps();

            // No display_artist. Release artists are canonical in
            // release_artist; a denormalised credit here is a projection and
            // is deferred (see ADR-0011).
            $table->unique(['release_id', 'disc_number', 'track_number']);
            $table->unique(['release_id', 'track_id']);
            $table->index('track_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('release_tracks');
    }
};
