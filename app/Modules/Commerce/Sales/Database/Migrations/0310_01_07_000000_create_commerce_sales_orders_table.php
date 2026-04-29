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
        Schema::create('commerce_sales_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index()->constrained('companies');
            $table->string('channel');
            $table->string('external_order_id');
            $table->string('marketplace_id')->nullable();
            $table->string('buyer_username')->nullable();
            $table->string('buyer_email')->nullable();
            $table->string('status')->nullable()->index();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->unsignedBigInteger('total_amount')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'channel', 'external_order_id'], 'commerce_sales_orders_external_unique');
            $table->index(['company_id', 'channel', 'ordered_at']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_sales_orders');
    }
};
