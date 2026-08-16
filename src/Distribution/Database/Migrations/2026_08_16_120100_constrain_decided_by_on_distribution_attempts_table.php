<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The person who decided cannot be deleted out from under the decision.
 *
 * `decided_by` was added without a constraint, on the argument that SEC-001
 * deactivates accounts rather than deleting them so the reference stays
 * resolvable anyway. Review disagreed, and correctly: "nothing deletes users
 * today" is a property of the current code, not of the schema, and the row it
 * points at is the record of who took responsibility for a value the platform
 * never received from a distributor. An orphaned `decided_by` is an
 * append-only log that has quietly lost the only part a person is accountable
 * for.
 *
 * **RESTRICT, deliberately, and not the two alternatives:**
 *
 *   - `cascadeOnDelete` would let deleting a user delete the attempt rows —
 *     erasing history by way of the users table, which is precisely what an
 *     append-only log exists to prevent.
 *   - `nullOnDelete` would keep the row and lose the name, leaving a decision
 *     that says a person made it and cannot say who. Worse than refusing,
 *     because it looks complete.
 *
 * RESTRICT makes the deletion fail. That is the honest outcome: SaniTube
 * deactivates, and an installation that genuinely must remove a person has to
 * decide what happens to the decisions they took, rather than have the
 * database decide silently.
 *
 * SQLite enforces this only with `PRAGMA foreign_keys` on, which Laravel sets;
 * MySQL and MariaDB always do. Tested on all four.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_attempts', function (Blueprint $table): void {
            $table->foreign('decided_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('distribution_attempts', function (Blueprint $table): void {
            $table->dropForeign(['decided_by']);
        });
    }
};
