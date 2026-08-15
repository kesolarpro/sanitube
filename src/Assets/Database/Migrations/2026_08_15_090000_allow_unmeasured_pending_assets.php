<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A PENDING asset has no checksum, because nothing has been hashed yet.
 *
 * ARCH-002 declared `sha256`, `byte_size` and `mime_type` NOT NULL, which was
 * right for the only state it modelled: a row describing bytes that exist. The
 * upload workflow adds the state before that one — the asset is registered,
 * then the bytes arrive — and in it these three are genuinely unknown.
 *
 * The alternative was a placeholder checksum on every pending row. A column
 * whose value is sometimes a hash and sometimes a lie is worse than a nullable
 * one: NULL can be checked, and `AssetObserver` now refuses any asset that
 * reaches STORED or VERIFIED without all three. The guarantee moves from the
 * column definition to the invariant, and gets stronger on the way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->char('sha256', 64)->nullable()->change();
            $table->unsignedBigInteger('byte_size')->nullable()->change();
            $table->string('mime_type', 191)->nullable()->change();
        });
    }

    /**
     * Reversible only while no asset is still unmeasured.
     *
     * That is not an oversight. Restoring NOT NULL when a PENDING asset exists
     * would mean inventing a checksum for bytes nobody has hashed, and a
     * fabricated sha256 is exactly the value that makes a later verification
     * quarantine an innocent master.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->char('sha256', 64)->nullable(false)->change();
            $table->unsignedBigInteger('byte_size')->nullable(false)->change();
            $table->string('mime_type', 191)->nullable(false)->change();
        });
    }
};
