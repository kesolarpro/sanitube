<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contributors', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->string('legal_name');
            $table->string('display_name')->nullable();
            $table->string('type', 32);
            $table->char('country', 2)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('legal_name');
            $table->index('type');
        });

        // IPI and ISNI are not columns here: they are registry identifiers and
        // live in external_identifiers with the rest, so they gain revocation,
        // provenance and uniqueness for free.
    }

    public function down(): void
    {
        Schema::dropIfExists('contributors');
    }
};
