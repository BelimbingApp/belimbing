<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_marketplace_listing_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index()->constrained('companies');
            $table->foreignId('item_id')->nullable()->constrained('commerce_inventory_items')->nullOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('commerce_marketplace_listings')->nullOnDelete();
            $table->string('channel');
            $table->string('marketplace_id');
            $table->string('external_sku')->nullable();
            $table->string('title')->nullable();
            $table->string('category_id')->nullable();
            $table->string('status')->default('draft');
            $table->string('management_state')->default('local');
            $table->json('aspect_values')->nullable();
            $table->json('mapped_aspects')->nullable();
            $table->json('policy_ids')->nullable();
            $table->string('merchant_location_key')->nullable();
            $table->json('photo_asset_ids')->nullable();
            $table->string('readiness_status')->default('unchecked');
            $table->json('readiness_snapshot')->nullable();
            $table->timestamp('metadata_checked_at')->nullable();
            $table->string('metadata_version_key')->nullable();
            $table->string('publish_intent')->nullable();
            $table->text('last_failure_summary')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'channel', 'marketplace_id'], 'commerce_marketplace_drafts_scope_index');
            $table->index(['company_id', 'item_id']);
            $table->index(['company_id', 'listing_id']);
            $table->index(['company_id', 'channel', 'readiness_status'], 'commerce_marketplace_drafts_readiness_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_marketplace_listing_drafts');
    }
};
