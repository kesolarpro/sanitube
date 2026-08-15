<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One source inside a batch, and the platform's idempotency boundary.
 *
 * The unique index on `(ingestion_key, active_marker)` is what makes "the same
 * item submitted twice is not processed twice" a guarantee of the database
 * rather than a habit of the code. `active_marker` is 1 while the item is
 * PENDING or PROCESSING and NULL once it reaches any terminal state; every
 * engine in the support matrix treats NULLs as distinct inside a unique index,
 * so any number of finished attempts can coexist while at most one can be in
 * flight.
 *
 * The same trick carries "one active identifier per entity" in ARCH-002. It is
 * used again here for the same reason: no partial index, no CHECK constraint,
 * no engine-specific syntax, and no reliance on every future code path
 * remembering to look first.
 *
 * `ingestion_key` is a SHA-256 of the logical key rather than the key itself.
 * A cloud object reference can be a thousand characters and would not fit in
 * an index under utf8mb4; a digest is fixed-width, and the readable form is
 * kept beside it in `source_reference`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_items', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->foreignId('batch_id')->constrained('ingestion_batches')->cascadeOnDelete();

            $table->char('ingestion_key', 64);
            $table->string('source_reference', 1024);
            $table->string('original_filename');

            $table->string('status', 32);

            // 1 while in flight, NULL once terminal. See the class comment.
            $table->unsignedTinyInteger('active_marker')->nullable();

            // Restrict rather than cascade: deleting a batch must never take
            // an asset with it. The bytes outlive the import that brought
            // them in.
            $table->foreignId('asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->foreignId('candidate_id')->nullable();

            $table->string('failure_code', 32)->nullable();
            $table->text('failure_message')->nullable();

            $table->unsignedInteger('attempt_count')->default(0);

            $table->timestamps();

            $table->unique(['ingestion_key', 'active_marker'], 'ingestion_items_in_flight_unique');
            $table->index(['batch_id', 'status'], 'ingestion_items_batch_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_items');
    }
};
