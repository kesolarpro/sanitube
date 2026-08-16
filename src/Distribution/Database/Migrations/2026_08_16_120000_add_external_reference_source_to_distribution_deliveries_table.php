<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the external reference came from.
 *
 * A reference a distributor returned and one a person read off a dashboard and
 * typed in are not the same fact, and until now the column held both without
 * saying which. That matters because everything downstream — polling, takedown,
 * the screen — treats the reference as the distributor's own handle on the
 * package, and a typed one may simply be wrong.
 *
 * A string rather than a database enum: ARCH-003. Portability across the four
 * engines, and a value added later must not be a migration on a populated
 * table.
 *
 * Nullable, because a delivery has no reference until it has been handed over.
 * Existing rows are backfilled to PROVIDER_RESPONSE, which is true of every row
 * that can exist before this migration runs — manual resolution is introduced
 * by the same ticket, so there is no row it could mislabel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_deliveries', function (Blueprint $table): void {
            $table->string('external_reference_source', 32)->nullable()->after('external_release_id');
        });

        // Every reference that exists today arrived in a submission response.
        Schema::getConnection()
            ->table('distribution_deliveries')
            ->whereNotNull('external_release_id')
            ->update(['external_reference_source' => 'PROVIDER_RESPONSE']);
    }

    public function down(): void
    {
        Schema::table('distribution_deliveries', function (Blueprint $table): void {
            $table->dropColumn('external_reference_source');
        });
    }
};
