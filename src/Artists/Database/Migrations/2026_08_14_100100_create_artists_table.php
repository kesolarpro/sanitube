<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->string('name');
            $table->string('sort_name')->nullable();
            $table->string('slug')->unique();

            // VARCHAR rather than SQL ENUM: adding a value must never require
            // an ALTER TABLE, and ENUM semantics differ across the engines the
            // platform targets. The PHP backed enum is the authority.
            $table->string('type', 32);
            $table->char('country', 2)->nullable();
            $table->string('primary_locale', 16);

            $table->text('biography')->nullable();
            $table->boolean('is_owner_controlled')->default(true);
            $table->string('status', 32);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'name']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artists');
    }
};
