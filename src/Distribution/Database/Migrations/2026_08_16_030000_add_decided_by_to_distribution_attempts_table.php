<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who decided, when a person decided.
 *
 * Almost every attempt row records a conversation with a distributor and has
 * no human in it. One does not: DIST-001-H1's manual resolution, where
 * somebody looks at the distributor's own dashboard and tells SaniTube whether
 * a submission arrived. That is the single place in this module where a value
 * the platform never received is written as though it had been, and a record
 * of who typed it is the least that owes.
 *
 * Nullable, and null on every other kind of attempt. A default of "system"
 * would make the column unable to answer the only question it is for.
 *
 * No foreign key: SEC-001 deactivates accounts rather than deleting them, so
 * the reference stays resolvable, and a constraint here would make the
 * append-only attempt log deletable by way of the users table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_attempts', function (Blueprint $table): void {
            $table->unsignedBigInteger('decided_by')->nullable()->after('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('distribution_attempts', function (Blueprint $table): void {
            $table->dropColumn('decided_by');
        });
    }
};
