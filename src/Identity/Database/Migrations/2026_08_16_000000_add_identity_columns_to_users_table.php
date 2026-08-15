<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Turns Laravel's stock `users` table into SaniTube's.
 *
 * Additive on purpose. The framework's table already carries a name, an email,
 * a hashed password and a remember token, and all four are exactly right;
 * replacing it would mean re-deriving Laravel's own password reset and
 * remember-me plumbing for no benefit.
 *
 * `uuid` is added for the reason every other table has one: an internal id in
 * a URL leaks how many rows exist, and `getRouteKeyName()` returns the uuid.
 *
 * `is_active` rather than a delete. Someone who leaves a label is not somebody
 * whose past decisions should disappear — `reviewed_by` on a candidate and
 * `created_by` on a batch both point here, and a deleted row would orphan the
 * provenance those columns exist to record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->char('uuid', 36)->nullable()->after('id');
            $table->string('role', 32)->default('MEMBER')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
        });

        // Backfill before the unique index, so an installation that already
        // has users does not fail on a table full of null uuids.
        foreach (DB::table('users')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('users')
                ->where('id', $id)
                ->update(['uuid' => (string) Str::uuid7()]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('uuid', 'users_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_uuid_unique');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['uuid', 'role', 'is_active', 'last_login_at']);
        });
    }
};
