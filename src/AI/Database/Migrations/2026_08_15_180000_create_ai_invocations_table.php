<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every call to a language model, recorded.
 *
 * Two reasons, and neither is optional.
 *
 * **Auditability.** `AiPrompt` carries a `promptVersion` so that a prompt
 * later found to be wrong can be traced to the records it touched — which is
 * only true if the version was written down at the time. A catalogue that
 * cannot answer "what suggested this, and which version of the instruction"
 * cannot undo a bad batch.
 *
 * **Cost.** A per-token vendor bill with no local record of what was spent on
 * what is a bill nobody can check.
 *
 * `prompt_text` and `completion_text` are nullable and left null unless
 * `ai.ledger.store_text` is on. They are the columns most likely to
 * accumulate copies of catalogue data, and a ledger is not a second database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_invocations', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->string('provider', 64);
            $table->string('model', 128)->nullable();

            // What this call was for, in SaniTube's terms rather than the
            // vendor's — "suggest_title", not "chat.completions". A ledger
            // grouped by vendor endpoint answers no question anyone asks.
            $table->string('purpose', 64);
            $table->string('prompt_version', 32);

            $table->boolean('succeeded');

            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // longText, not json: MariaDB's json is an alias for
            // longtext/utf8mb4_bin while MySQL 8's is a native binary type,
            // and the divergence fails the collation guardrail.
            $table->longText('prompt_text')->nullable();
            $table->longText('completion_text')->nullable();

            $table->text('failure_message')->nullable();

            $table->timestamps();

            // What the two real questions are: "what did this cost last
            // month" and "which calls used the prompt version we just found
            // to be wrong".
            $table->index(['provider', 'created_at'], 'ai_invocations_provider_created_index');
            $table->index(['purpose', 'prompt_version'], 'ai_invocations_purpose_version_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_invocations');
    }
};
