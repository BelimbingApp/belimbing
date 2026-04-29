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
        Schema::create('commerce_sales_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index()->constrained('companies');
            $table->foreignId('order_id')->constrained('commerce_sales_orders')->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained('commerce_sales_order_lines')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('commerce_inventory_items')->nullOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('commerce_marketplace_listings')->nullOnDelete();
            $table->string('channel');
            $table->string('external_sale_id')->nullable();
            $table->timestamp('sold_at')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('sale_amount')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->unsignedBigInteger('cost_basis_amount')->nullable();
            $table->unsignedBigInteger('fee_amount')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique('order_line_id', 'commerce_sales_sales_order_line_unique');
            $table->unique(['company_id', 'channel', 'external_sale_id'], 'commerce_sales_sales_external_unique');
            $table->index(['company_id', 'channel', 'sold_at']);
            $table->index(['company_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_sales_sales');
    }
};
