<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_identifier_revocations', function (Blueprint $table): void {
            $table->id();

            // Unique: revocation is a one-way, once-only event. A second
            // revocation of the same identifier is a bug in the caller, and
            // the database says so rather than quietly recording it twice.
            $table->foreignId('external_identifier_id')
                ->unique()
                ->constrained('external_identifiers')
                ->restrictOnDelete();

            $table->string('reason');
            $table->timestamp('revoked_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_identifier_revocations');
    }
};
