<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            // Trash is three columns, not a status.
            //
            // `AssetStatus` describes what is known about the *bytes* --
            // pending, stored, verified, missing, quarantined -- and a trashed
            // master is still stored and still verified. Folding "somebody set
            // this aside" into that enum would make `isImmutable()` answer a
            // question it was not asked, and would lose the verification state
            // the moment an asset was trashed, so restoring it would mean
            // re-reading the object to find out what it had already known.
            //
            // All three are nullable, which is also what keeps this migration
            // portable: a NOT NULL timestamp added after `stored_at` would
            // inherit MySQL's invalid implicit default (ADR-0017).
            $table->timestamp('trashed_at')->nullable()->after('verified_at');
            $table->foreignId('trashed_by_user_id')->nullable()->after('trashed_at')
                ->constrained('users')->nullOnDelete();

            // A machine-readable code, never a sentence. The interface
            // translates it; the column outlives whatever English was current.
            $table->string('trash_reason', 64)->nullable()->after('trashed_by_user_id');

            $table->index('trashed_at', 'assets_trashed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropIndex('assets_trashed_at_index');
            $table->dropForeign(['trashed_by_user_id']);
            $table->dropColumn(['trashed_at', 'trashed_by_user_id', 'trash_reason']);
        });
    }
};
