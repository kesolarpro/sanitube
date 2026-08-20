<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One request to make a cover, and everything needed to decide whether to make
 * another.
 *
 * **This table is the ledger.** ART-004 adds no counter anywhere else, for the
 * reason `GenerationSpendGuard` gives about music: `submitted_at` is written
 * exactly when a request left this server for a provider, which is the
 * definition of the thing being counted. A parallel total would be a second
 * source of truth and would drift the first time a row was removed by hand.
 *
 * **Operational safety, not finance.** There is no price, no currency, no
 * balance and no invoice here. `estimated_cost_hint` is a free-text note a
 * provider may return about its own pricing tier — recorded because an operator
 * comparing two providers wants it, and deliberately a string so that nothing
 * can be tempted to add it up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artwork_generations', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            // What this cover is for. Nullable because a person may generate
            // one to look at before deciding which release it belongs to, and
            // nulled rather than cascaded on delete so the spend record — the
            // fact that money was spent — survives the release going away.
            $table->foreignId('release_id')->nullable()->constrained('releases')->nullOnDelete();

            $table->string('provider', 64);
            $table->string('provider_reference', 191)->nullable();
            $table->string('status', 32);

            $table->text('prompt');
            $table->string('model', 128)->nullable();
            $table->string('quality', 32)->nullable();
            $table->unsignedInteger('requested_width')->nullable();
            $table->unsignedInteger('requested_height')->nullable();

            // What the request produced, when it produced anything. Nulled on
            // asset deletion for the same reason as the release above.
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();

            // A code, never a sentence: the platform ships six languages and a
            // provider's English does not travel. `ArtworkRefusal` is the
            // vocabulary.
            $table->string('failure_code', 64)->nullable();

            // Free text, and a string on purpose. See the class note above.
            $table->string('estimated_cost_hint', 128)->nullable();

            $table->unsignedInteger('attempts')->default(0);

            // ADR-0017: every non-nullable timestamp states its default, and
            // these are all nullable because every one of them means "has not
            // happened yet" until it has.
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->foreignId('requested_by')->nullable();

            $table->timestamps();

            // The spend guard's query: count submissions by provider inside a
            // rolling window.
            $table->index(['provider', 'submitted_at'], 'artwork_generations_provider_submitted_index');

            // The circuit breaker's query: the most recent outcomes for one
            // provider, newest first.
            $table->index(['provider', 'status', 'completed_at'], 'artwork_generations_provider_outcome_index');

            $table->index(['status', 'created_at'], 'artwork_generations_status_created_index');
            $table->index('release_id', 'artwork_generations_release_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artwork_generations');
    }
};
