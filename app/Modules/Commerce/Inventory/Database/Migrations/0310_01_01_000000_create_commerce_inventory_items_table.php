<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('commerce_inventory_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index()->constrained('companies');
            $table->string('sku')->unique();
            $table->string('status')->default('draft')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('unit_cost_amount')->nullable();
            $table->unsignedBigInteger('target_price_amount')->nullable();
            $table->char('currency_code', 3)->default('MYR');
            $table->json('attributes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commerce_inventory_items');
    }
};
