<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One release, handed to one distributor.
 *
 * **`UNIQUE (release_id, provider)` is the load-bearing line in this file.**
 * The same release submitted twice to the same distributor is what a store
 * sees as a duplicate delivery, and duplicate deliveries are how a label's
 * whole catalogue gets flagged. One row per pair, enforced by the database
 * rather than by a service remembering to look.
 *
 * `idempotency_key` is derived once, when the delivery is created, and never
 * regenerated. Every retry — including one after a timeout where SaniTube does
 * not know whether the distributor received the package — sends the same key,
 * so a distributor that honours it can recognise the repeat. A key regenerated
 * per attempt would be no key at all.
 *
 * There is no `distributors` table. A distributor is an adapter and a set of
 * credentials, both of which live in configuration: making it a row would
 * invite someone to create one the platform has no code for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->foreignId('release_id')->constrained('releases')->restrictOnDelete();
            $table->string('provider', 64);

            $table->string('status', 32);

            // The distributor's own handle on this release. Null until it
            // gives us one, and never shown to an API caller.
            $table->string('external_release_id', 191)->nullable();

            $table->char('idempotency_key', 64);

            $table->text('failure_reason')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('live_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->foreignId('created_by')->nullable();

            $table->timestamps();

            $table->unique(['release_id', 'provider'], 'distribution_deliveries_release_provider_unique');
            $table->index(['provider', 'status'], 'distribution_deliveries_provider_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_deliveries');
    }
};
