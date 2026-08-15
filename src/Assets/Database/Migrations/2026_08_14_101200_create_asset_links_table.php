<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_links', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->morphs('linkable');

            // Secondary attachments only. MASTER and COVER are structurally
            // absent from AssetLinkRole: those facts live in
            // tracks.master_asset_id and releases.cover_asset_id, and having
            // one home each is what stops them contradicting one another.
            $table->string('role', 32);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['asset_id', 'linkable_type', 'linkable_id', 'role'], 'asset_links_unique');
            $table->index(['linkable_type', 'linkable_id', 'role', 'position'], 'asset_links_linkable_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_links');
    }
};
