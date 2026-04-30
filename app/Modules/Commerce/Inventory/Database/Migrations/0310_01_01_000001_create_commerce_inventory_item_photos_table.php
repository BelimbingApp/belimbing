<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_inventory_item_photos', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('item_id')
                ->constrained('commerce_inventory_items')
                ->cascadeOnDelete();

            $table->foreignId('media_asset_id')
                ->constrained('base_media_assets')
                ->restrictOnDelete();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['item_id', 'sort_order']);
            $table->unique('media_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_inventory_item_photos');
    }
};
