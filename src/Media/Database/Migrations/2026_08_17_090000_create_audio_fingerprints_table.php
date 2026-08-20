<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each asset sounds like, compactly, as evidence rather than a verdict.
 *
 * **The fingerprint is stored, not a score.** A similarity percentage is a
 * decision made with today's threshold, and thresholds get recalibrated once
 * real data arrives. Keeping the fingerprint means a hundred thousand
 * comparisons can be re-decided without re-reading a single master — and
 * re-reading fifty thousand masters to change one number is the sort of thing
 * nobody actually does, so the number never gets changed.
 *
 * **`duration_seconds` is indexed because it is the pre-filter.** Comparing
 * every fingerprint against every other is O(n²): at fifty thousand assets that
 * is 1.25 billion pairs, which is not a slow query, it is an impossible one.
 * Two recordings of different lengths are not the same recording, so duration
 * narrows the candidate set to a handful before any fingerprint is compared.
 * This index is what makes acoustic deduplication feasible at all.
 *
 * **`fingerprint_hash` is a fast path, never an identity.** Byte-identical
 * fingerprints are the same decode of the same audio and are worth finding with
 * an index. Files differing by one sample hash differently and are still the
 * same recording — which is exactly why the raw fingerprint is kept too.
 *
 * `algorithm` and `tool_version` travel on every row because fingerprints from
 * different versions are not reliably comparable. A recalibration needs to know
 * which rows it may compare and which it must recompute.
 *
 * `fingerprint` is longText with no cast: it is an opaque string from the tool.
 * The JSON column builder is forbidden platform-wide (ADR-0002) — naming that
 * call even in a comment trips the scanner enforcing it — and it would buy
 * nothing anyway, because nothing queries inside the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_fingerprints', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            // Restrict, like audio_analyses: evidence about an asset must not
            // outlive it silently, and removing an asset that has been
            // compared should be a deliberate act.
            $table->foreignId('asset_id')->constrained('assets')->restrictOnDelete();

            $table->string('algorithm', 64);
            $table->string('tool', 64);
            $table->string('tool_version', 32);

            $table->longText('fingerprint');
            $table->char('fingerprint_hash', 64);

            // The tool's own reading of what it decoded, which may disagree
            // with what ffprobe read — and a disagreement is information.
            $table->unsignedInteger('duration_seconds');

            $table->timestamp('computed_at');
            $table->timestamps();

            // One fingerprint per asset per algorithm version. Recomputing
            // under a new version adds a row rather than losing the old one.
            $table->unique(['asset_id', 'algorithm'], 'audio_fingerprints_asset_algorithm_unique');

            // The pre-filter. Without it there is no feasible comparison.
            $table->index(['algorithm', 'duration_seconds'], 'audio_fingerprints_duration_index');

            $table->index('fingerprint_hash', 'audio_fingerprints_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_fingerprints');
    }
};
