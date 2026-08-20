<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_relations', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();

            // Direction is meaningful and is not normalised away. `asset_id`
            // is the one being evaluated -- the arrival -- and
            // `related_asset_id` is what the platform already held. A reviewer
            // deciding which copy to keep needs to know which one turned up
            // second, and a symmetric pair loses that.
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignId('related_asset_id')->constrained('assets')->cascadeOnDelete();

            // Strings rather than a database ENUM: ADR-0002 forbids it
            // platform-wide, because adding a value to one is an ALTER on
            // MySQL, a rebuild on others, and unsupported on SQLite.
            $table->string('level', 32);
            $table->string('basis', 32);
            $table->string('decision', 24);

            // Signed on purpose, all three of them.
            //
            // `offset_frames` is genuinely negative when the arrival starts
            // earlier than what it matched. The other two never are -- and are
            // still signed, because MySQL and MariaDB evaluate unsigned minus
            // unsigned as unsigned and abort the statement on underflow, so an
            // ORDER BY that subtracts one of these columns would work on
            // SQLite and fail on every other engine in the matrix. That is not
            // hypothetical; it is what MED-003 shipped and CI caught. See
            // ADR-0017.
            $table->integer('similarity_basis_points')->nullable();
            $table->integer('offset_frames')->nullable();
            $table->integer('overlap_frames')->nullable();

            // Which fingerprint version produced the measurement, so a later
            // recalibration can tell what it may reuse and what it must redo.
            $table->string('algorithm', 64)->nullable();

            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            // The default is explicit because the implicit one is invalid.
            // MySQL and MariaDB give the *first* TIMESTAMP column in a table
            // an implicit DEFAULT CURRENT_TIMESTAMP and every later NOT NULL
            // one an implicit zero-date default, which strict mode then
            // rejects outright:
            //
            //   1067 Invalid default value for 'detected_at'
            //
            // `decided_at` is declared above, so this column is the second and
            // inherits the broken default. Writing the default out means the
            // schema does not depend on the order two columns happen to appear
            // in -- and SQLite, which has no such rule, cannot warn about it.
            $table->timestamp('detected_at')->useCurrent();
            $table->timestamps();

            // One finding per pair per route. The same two assets can be found
            // by checksum and by fingerprint, and neither may overwrite the
            // other -- re-running the evaluation must not multiply the queue.
            $table->unique(['asset_id', 'related_asset_id', 'basis'], 'duplicate_relations_pair_basis_unique');

            // The review queue reads exactly this way: undecided first,
            // strongest evidence first.
            $table->index(['decision', 'level'], 'duplicate_relations_queue_index');
            $table->index('related_asset_id', 'duplicate_relations_related_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duplicate_relations');
    }
};
