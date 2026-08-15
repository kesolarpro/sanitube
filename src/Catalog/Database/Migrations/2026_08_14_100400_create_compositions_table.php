<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compositions', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->string('title');
            $table->string('alternative_title')->nullable();
            $table->string('language_code', 16);
            $table->unsignedSmallInteger('created_year')->nullable();
            $table->boolean('is_public_domain')->default(false);
            $table->string('status', 32);

            $table->timestamps();
            $table->softDeletes();

            $table->index('title');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compositions');
    }
};
