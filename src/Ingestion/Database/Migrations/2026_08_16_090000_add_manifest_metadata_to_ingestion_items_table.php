<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the operator's manifest said about this item.
 *
 * Stored on the item rather than only on the candidate, because the item is
 * what survives a failure. If an import crashes after the bytes land and
 * before a candidate exists, the retry has to know the manifest still claimed
 * this file was "Nocturne, Op. 9 No. 2, ISRC FRZ031400001" — and re-reading
 * the CSV is not an option, because the operator's laptop is not part of the
 * retry.
 *
 * `longText` with an array cast on the model, never the JSON column type.
 * MariaDB's is an alias for `longtext utf8mb4_bin` while MySQL 8's is a native
 * binary type, so one migration would produce two column types with two
 * comparison semantics across the support matrix. Platform-wide rule, enforced
 * by PortabilityTest — which reads the file as text, so naming the forbidden
 * builder call here would trip it.
 *
 * Nullable, because most items have no manifest. Null means "no manifest row",
 * which is a different fact from an empty one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingestion_items', function (Blueprint $table): void {
            $table->longText('manifest_metadata')->nullable()->after('original_filename');
        });
    }

    public function down(): void
    {
        Schema::table('ingestion_items', function (Blueprint $table): void {
            $table->dropColumn('manifest_metadata');
        });
    }
};
