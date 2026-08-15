<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            $table->morphs('identifiable');

            $table->string('type', 48);

            // Empty for globally-issued identifiers (ISRC, UPC, ISWC…), and
            // the counterparty's name for everything else. Being part of every
            // uniqueness rule is what lets the same DSP id exist under two
            // different services without either one blocking the other.
            $table->string('namespace', 64)->default('');

            $table->string('value', 191);
            $table->boolean('is_authoritative')->default(false);
            $table->string('source', 32);
            $table->timestamp('assigned_at');

            // 1 while active, NULL once revoked.
            //
            // This is what keeps "one active identifier of a given type per
            // entity" an engine-enforced guarantee rather than an application
            // convention. Every engine in the support matrix treats NULLs as
            // distinct inside a unique index, so any number of revoked rows
            // coexist while only one active row can exist. No partial index,
            // no CHECK constraint, no engine-specific syntax.
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);

            $table->timestamps();

            // Global identity: a given identifier value means one thing.
            $table->unique(['type', 'namespace', 'value'], 'external_identifiers_identity_unique');

            // One active identifier of each type+namespace per entity.
            $table->unique(
                ['identifiable_type', 'identifiable_id', 'type', 'namespace', 'active_marker'],
                'external_identifiers_active_unique',
            );

            $table->index(['identifiable_type', 'identifiable_id', 'type'], 'external_identifiers_entity_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_identifiers');
    }
};
