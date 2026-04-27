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
        Schema::create('commerce_catalog_descriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('commerce_inventory_items')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->text('body');
            $table->string('source')->default('manual')->index();
            $table->boolean('is_accepted')->default(false)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'version']);
            $table->index(['item_id', 'is_accepted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_catalog_descriptions');
    }
};
