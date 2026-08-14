<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('track_artist', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();

            $table->string('role', 32);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            // A track may carry several PRIMARY artists — collaborations are
            // normal and forcing a single one would misrepresent the credit.
            $table->unique(['track_id', 'artist_id', 'role']);
            $table->index(['track_id', 'role', 'position']);
            $table->index(['artist_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('track_artist');
    }
};
