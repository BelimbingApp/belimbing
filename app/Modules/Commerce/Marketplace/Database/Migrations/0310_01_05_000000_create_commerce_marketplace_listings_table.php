<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_marketplace_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index()->constrained('companies');
            $table->foreignId('item_id')->nullable()->constrained('commerce_inventory_items')->nullOnDelete();
            $table->string('channel');
            $table->string('external_listing_id')->nullable();
            $table->string('external_offer_id')->nullable();
            $table->string('external_sku')->nullable();
            $table->string('marketplace_id')->nullable();
            $table->string('title')->nullable();
            $table->string('status')->nullable()->index();
            $table->string('management_state')->default('imported')->index();
            $table->string('drift_status')->default('unknown')->index();
            $table->text('drift_summary')->nullable();
            $table->unsignedBigInteger('price_amount')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('listing_url')->nullable();
            $table->timestamp('listed_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'channel', 'external_listing_id'], 'commerce_marketplace_listing_external_unique');
            $table->index(['company_id', 'channel', 'external_sku'], 'commerce_marketplace_listings_company_channel_sku_index');
            $table->index(['company_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_marketplace_listings');
    }
};
