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
        Schema::create('commerce_catalog_attribute_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('commerce_inventory_items')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('commerce_catalog_attributes')->cascadeOnDelete();
            $table->json('value')->nullable();
            $table->string('display_value')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'attribute_id']);
            $table->index('attribute_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_catalog_attribute_values');
    }
};
