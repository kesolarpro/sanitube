<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One request to a generation provider.
 *
 * The idempotency boundary is `(provider, provider_job_id)`, unique. A poll
 * that arrives twice, a webhook replayed, a retried submit that actually
 * succeeded the first time — all of them name the same provider job, and the
 * database refuses to let that become two generations. `provider_job_id` is
 * null until the provider answers, and NULLs stay distinct inside a unique
 * index on every engine in the matrix, so any number of unsubmitted drafts
 * coexist.
 *
 * That is the same trick ARCH-002 uses for one active identifier per track and
 * ING-001 uses for one in-flight item per key: no partial index, no CHECK, no
 * engine-specific syntax.
 *
 * `parameters` is longText with an array cast, never `json`. MariaDB's `json`
 * is an alias for longtext/utf8mb4_bin while MySQL 8's is a native binary
 * type, and the divergence fails the ARCH-003 collation guardrail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_generations', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->foreignId('project_id')->nullable()->constrained('generation_projects')->nullOnDelete();

            $table->string('provider', 64);
            $table->string('provider_job_id', 191)->nullable();

            $table->string('status', 32);

            $table->text('prompt');
            $table->text('lyrics')->nullable();
            $table->text('style_prompt')->nullable();

            $table->string('language_code', 16);
            $table->boolean('instrumental')->default(false);

            $table->string('model', 128)->nullable();

            $table->longText('parameters')->nullable();

            /*
             * Commercial provenance. A generation is not sellable because it
             * exists — that depends on the provider's terms on the day it was
             * made, which is what the snapshot records.
             */
            $table->foreignId('terms_snapshot_id')->nullable()
                ->constrained('provider_terms_snapshots')->nullOnDelete();
            $table->string('commercial_rights_status', 32);

            $table->text('failure_reason')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->unsignedInteger('poll_count')->default(0);

            $table->foreignId('created_by')->nullable();

            $table->timestamps();

            // One provider job, one generation. See the class docblock.
            $table->unique(['provider', 'provider_job_id'], 'music_generations_provider_job_unique');

            $table->index(['status', 'created_at'], 'music_generations_status_created_index');
            $table->index('project_id', 'music_generations_project_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_generations');
    }
};
