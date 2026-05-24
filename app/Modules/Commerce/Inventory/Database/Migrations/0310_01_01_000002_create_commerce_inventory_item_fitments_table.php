<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_inventory_item_fitments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('commerce_inventory_items')->cascadeOnDelete();
            $table->string('channel')->nullable();
            $table->string('marketplace_id')->nullable();
            $table->string('category_tree_id')->nullable();
            $table->string('category_id')->nullable();
            $table->boolean('is_universal')->default(false);
            $table->json('compatibility_properties')->nullable();
            $table->string('display_year')->nullable();
            $table->string('display_make')->nullable();
            $table->string('display_model')->nullable();
            $table->string('display_trim')->nullable();
            $table->string('display_engine')->nullable();
            $table->string('source')->default('operator');
            $table->string('confidence')->default('seller_confirmed');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'item_id']);
            $table->index(['company_id', 'channel', 'marketplace_id', 'category_tree_id', 'category_id'], 'commerce_inventory_item_fitments_marketplace_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_inventory_item_fitments');
    }
};
