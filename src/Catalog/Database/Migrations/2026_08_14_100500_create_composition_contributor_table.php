<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('composition_contributor', function (Blueprint $table): void {
            $table->id();

            // A pure link table: removing either side removes the link, which
            // is the one place cascading is the correct semantics.
            $table->foreignId('composition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contributor_id')->constrained()->cascadeOnDelete();

            $table->string('role', 32);

            // The credited share at catalogue time. Not the Rights model:
            // RGT-001 adds territory, term and administration and must be free
            // to disagree with what was captured here.
            $table->decimal('share', 9, 6)->nullable();
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['composition_id', 'contributor_id', 'role']);
            $table->index(['composition_id', 'role', 'position']);
            $table->index(['contributor_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composition_contributor');
    }
};
