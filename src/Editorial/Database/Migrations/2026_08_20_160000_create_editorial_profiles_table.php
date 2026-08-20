<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How one imprint wants its catalogue to sound and read.
 *
 * **A separate table from `artists` on purpose.** An artist is a public credit
 * and this is editorial policy; one artist can be released under several
 * profiles and one profile can credit several artists, so folding these columns
 * onto the artist row would make both impossible and would put policy in a
 * table distribution reads as identity.
 *
 * **Everything specific to a label lives in these rows, never in code.** A
 * second imprint on the same installation is a second row, not a fork.
 *
 * `longText` with array casts, never `json()`: ADR-0002, because MariaDB's
 * `json` is an alias for `longtext` with a check constraint while MySQL 8's is
 * a native type, and the divergence fails the collation guardrail.
 *
 * The `is_active` flag is a boolean rather than a status enum. There are
 * exactly two states an imprint can be in from this table's point of view --
 * one you are still releasing under and one you are not -- and an enum would
 * invite a third that nothing knows how to interpret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_profiles', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->string('name');

            // Unique, because a profile is referred to by name in a console
            // command and in a plan, and two imprints called the same thing is
            // an ambiguity nobody can resolve later.
            $table->string('slug', 128)->unique();

            $table->text('summary')->nullable();

            // The credit this imprint usually releases under. Nullable and a
            // preference: a profile with no default artist is an imprint that
            // decides per release, which is an ordinary way to run one.
            $table->foreignId('default_artist_id')->nullable()->constrained('artists')->nullOnDelete();

            $table->string('default_language', 16)->nullable();

            $table->longText('preferred_genres')->nullable();
            $table->longText('preferred_moods')->nullable();
            $table->longText('preferred_themes')->nullable();

            // Words and themes this imprint does not use. Kept apart from the
            // preferences rather than expressed as negative ones, because a
            // model handed both in one list treats them as equally optional.
            $table->longText('avoided_terms')->nullable();

            $table->text('title_guidance')->nullable();
            $table->text('description_guidance')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'name'], 'editorial_profiles_active_name_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_profiles');
    }
};
