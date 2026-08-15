<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every time SaniTube spoke to a distributor about a delivery.
 *
 * Kept separately from the delivery because "this release was rejected" and
 * "we tried four times and the fourth failed for a different reason than the
 * first" are different facts — and only the second tells a label whether the
 * problem is their metadata or the distributor's service.
 *
 * Append-only. An attempt is something that happened.
 *
 * `response_summary` is a *summary*, never the raw payload: distributor
 * responses quote the request, and the request is signed with credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_attempts', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->foreignId('delivery_id')->constrained('distribution_deliveries')->cascadeOnDelete();

            $table->string('action', 32);
            $table->string('outcome', 32);

            $table->char('idempotency_key', 64);

            $table->text('response_summary')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index(['delivery_id', 'created_at'], 'distribution_attempts_delivery_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_attempts');
    }
};
