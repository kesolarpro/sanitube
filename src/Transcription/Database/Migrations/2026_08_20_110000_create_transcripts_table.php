<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcripts', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();

            $table->string('provider', 64);
            $table->string('provider_version', 32);
            $table->string('model', 128)->nullable();

            // The provider's guess, and stored as one. Deliberately not written
            // to the track: a release's language is an editorial decision with
            // distribution consequences, and a model that heard mostly
            // instrumental will happily report English.
            $table->string('detected_language', 16)->nullable();

            // What the operator said it was, if anything. Kept beside the guess
            // so a disagreement between the two is visible rather than lost --
            // that disagreement is usually the most interesting thing on the
            // row.
            $table->string('language_hint', 16)->nullable();

            $table->longText('text');

            // How much audio the answer covers. A provider that transcribed the
            // first two minutes of a nine-minute track produced a correct
            // transcript of something other than the track, and without this
            // column nothing downstream can tell.
            $table->integer('covered_seconds')->nullable();

            $table->timestamp('transcribed_at')->useCurrent();
            $table->timestamps();

            // One transcript per asset per provider version. Re-running must
            // refresh rather than accumulate, and a version bump is what makes
            // a genuinely new transcript a new row.
            $table->unique(['asset_id', 'provider', 'provider_version'], 'transcripts_asset_provider_unique');
        });

        Schema::create('transcript_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transcript_id')->constrained('transcripts')->cascadeOnDelete();

            // Rows rather than a JSON column. ADR-0002 forbids the JSON column
            // builder platform-wide, but the better reason is that segments are
            // the part anybody will want to query -- "what is said around 2:14"
            // is a WHERE clause here and a full-table decode there.
            //
            // Signed on purpose (ADR-0017): these are arithmetic operands, and
            // an unsigned column makes `end_ms - start_ms` abort on MySQL and
            // MariaDB while working on SQLite.
            $table->integer('position');
            $table->integer('start_ms');
            $table->integer('end_ms');
            $table->text('text');

            $table->unique(['transcript_id', 'position'], 'transcript_segments_position_unique');
            $table->index(['transcript_id', 'start_ms'], 'transcript_segments_start_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcript_segments');
        Schema::dropIfExists('transcripts');
    }
};
