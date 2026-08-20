<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('background_work_state', function (Blueprint $table): void {
            $table->id();

            // A row, not a config value and not a cache entry.
            //
            // A kill switch has to work when the thing that is wrong is the
            // cache, and it has to survive `config:cache` -- which on this
            // platform's cPanel baseline is run at deploy time and then not
            // again for weeks. An operator stopping a runaway backfill cannot
            // be asked to edit a file and redeploy.
            $table->boolean('is_paused')->default(false);

            // A machine-readable code, never a sentence. The interface
            // translates it, and an operator scanning a year of pauses needs
            // values that group.
            $table->string('reason', 64)->nullable();

            $table->foreignId('paused_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('background_work_state');
    }
};
