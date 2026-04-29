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
        Schema::create('commerce_sales_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index()->constrained('companies');
            $table->foreignId('order_id')->constrained('commerce_sales_orders')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('commerce_inventory_items')->nullOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('commerce_marketplace_listings')->nullOnDelete();
            $table->string('external_line_item_id')->nullable();
            $table->string('external_listing_id')->nullable();
            $table->string('external_sku')->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price_amount')->nullable();
            $table->unsignedBigInteger('line_total_amount')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'external_line_item_id'], 'commerce_sales_order_lines_external_unique');
            $table->index(['company_id', 'external_listing_id']);
            $table->index(['company_id', 'external_sku']);
            $table->index(['company_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_sales_order_lines');
    }
};
