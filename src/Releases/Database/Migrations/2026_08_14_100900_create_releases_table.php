<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->string('type', 32);
            $table->string('title');
            $table->string('version_title')->nullable();

            // The canonical cover. As with a track's master, this foreign key
            // is the only place it is recorded.
            $table->foreignId('cover_asset_id')->nullable()->constrained('assets')->restrictOnDelete();

            $table->string('label_name')->nullable();
            $table->string('catalogue_number', 64)->nullable();
            $table->date('release_date')->nullable();
            $table->date('original_release_date')->nullable();
            $table->string('language_code', 16);
            $table->string('p_line')->nullable();
            $table->string('c_line')->nullable();

            $table->string('status', 32);

            $table->timestamps();
            $table->softDeletes();

            // No primary_artist_id, for the same reason as tracks.
            $table->index('title');
            $table->index(['status', 'id']);
            $table->index('release_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
