<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_marketplace_aspect_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('catalog_attribute_id')->nullable();
            $table->foreign('catalog_attribute_id', 'cms_mkt_asp_map_attr_fk')
                ->references('id')->on('commerce_catalog_attributes')->nullOnDelete();
            $table->string('channel');
            $table->string('marketplace_id');
            $table->string('category_tree_id')->nullable();
            $table->string('category_id')->default('*');
            $table->string('internal_attribute_code');
            $table->string('ebay_aspect_name');
            $table->string('value_normalization')->default('copy');
            $table->json('enum_values')->nullable();
            $table->string('requirement_status')->default('unknown');
            $table->string('mapping_confidence')->default('manual');
            $table->boolean('is_enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique([
                'company_id',
                'channel',
                'marketplace_id',
                'category_id',
                'internal_attribute_code',
                'ebay_aspect_name',
            ], 'commerce_marketplace_aspect_mappings_unique');
            $table->index(['company_id', 'channel', 'marketplace_id', 'category_tree_id', 'category_id'], 'commerce_marketplace_aspect_mappings_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_marketplace_aspect_mappings');
    }
};
