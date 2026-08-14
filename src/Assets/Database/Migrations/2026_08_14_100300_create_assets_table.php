<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->string('kind', 32);

            // Lineage. Restrict rather than cascade: deleting a master must
            // never silently take its derivatives with it.
            $table->foreignId('parent_asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->foreignId('duplicate_of_asset_id')->nullable()->constrained('assets')->restrictOnDelete();

            $table->string('disk', 64);
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 191);
            $table->unsignedBigInteger('byte_size');

            // Deliberately not unique. The same bytes legitimately arrive
            // twice — a re-upload, the same master on two releases — and the
            // right response is to record it as a duplicate for review, not to
            // reject the row and lose the fact that it happened.
            $table->char('sha256', 64);

            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedSmallInteger('bit_depth')->nullable();
            $table->unsignedSmallInteger('channels')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->string('status', 32);
            $table->timestamp('stored_at')->nullable();
            $table->timestamp('verified_at')->nullable();

            // No soft deletes: an asset row is the record that bytes exist
            // somewhere. Hiding it would not unwrite the file, it would only
            // make the file unaccounted for.
            $table->timestamps();

            $table->index('sha256');
            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
