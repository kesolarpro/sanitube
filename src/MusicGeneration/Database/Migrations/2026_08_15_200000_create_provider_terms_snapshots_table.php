<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a provider's terms said, on the day the audio was made.
 *
 * Terms change. A provider that allowed commercial use on the plan an account
 * held in March may not on the plan it holds in November, and the recording
 * made in March is still in the catalogue. Without a snapshot, "may we sell
 * this?" can only be answered by today's terms — which is the wrong question
 * about a work that already exists.
 *
 * **Nothing here is scraped or inferred.** Every column is entered by a person
 * who read the licence, and `source_reference` is where they read it. A
 * platform that guessed at a provider's terms would be inventing the one fact
 * a distributor will hold the label to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_terms_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->string('provider', 64);
            $table->string('plan', 128)->nullable();
            $table->string('terms_version', 64)->nullable();
            $table->date('effective_date')->nullable();

            // Nullable on purpose, and not the same as false. Null means
            // nobody has established it; false means somebody read the terms
            // and they say no.
            $table->boolean('commercial_use_allowed')->nullable();

            $table->text('ownership_notes')->nullable();

            // Where a human read this. A URL, a contract reference, a support
            // ticket. An entry nobody can trace back is an entry nobody can
            // defend.
            $table->string('source_reference', 1024)->nullable();

            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['provider', 'captured_at'], 'provider_terms_provider_captured_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_terms_snapshots');
    }
};
