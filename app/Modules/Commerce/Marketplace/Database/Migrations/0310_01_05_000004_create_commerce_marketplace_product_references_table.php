<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_marketplace_product_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('commerce_inventory_items')->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('commerce_marketplace_listings')->cascadeOnDelete();
            $table->foreignId('listing_draft_id')->nullable()->constrained('commerce_marketplace_listing_drafts')->cascadeOnDelete();
            $table->string('channel');
            $table->string('marketplace_id')->nullable();
            $table->string('reference_type');
            $table->string('external_product_id');
            $table->string('target_key');
            $table->string('title')->nullable();
            $table->json('facts')->nullable();
            $table->string('source')->default('imported');
            $table->string('review_status')->default('suggested');
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique([
                'company_id',
                'channel',
                'reference_type',
                'external_product_id',
                'target_key',
            ], 'commerce_marketplace_product_references_unique');
            $table->index(['company_id', 'channel', 'marketplace_id', 'reference_type'], 'commerce_marketplace_product_references_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_marketplace_product_references');
    }
};
