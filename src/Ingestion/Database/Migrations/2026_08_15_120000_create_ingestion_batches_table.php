<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One import operation.
 *
 * Deliberately thin. There are no `imported_count` / `failed_count` columns,
 * because every one of them is derivable from `ingestion_items` and a
 * denormalised counter that drifts is worse than a query that is a few
 * milliseconds slower — it makes a batch *look* complete when it is not.
 *
 * If progress reporting ever needs materialised counters, they belong in a
 * rebuildable projection with a command that recomputes them, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_batches', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->string('source', 32);
            $table->string('status', 32);

            // Nullable until the Identity module exists. It is declared now
            // because backfilling provenance onto rows already imported is
            // guesswork, and the initial catalogue is imported once.
            $table->foreignId('created_by')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at'], 'ingestion_batches_status_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_batches');
    }
};
