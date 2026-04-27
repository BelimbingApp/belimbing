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
        Schema::create('commerce_catalog_attributes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index()->constrained('companies');
            $table->foreignId('category_id')->nullable()->constrained('commerce_catalog_categories')->cascadeOnDelete();
            $table->foreignId('product_template_id')->nullable()->constrained('commerce_catalog_product_templates')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('type')->default('text');
            $table->boolean('is_required')->default(false);
            $table->json('options')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'code']);
            $table->index(['category_id', 'sort_order']);
            $table->index(['product_template_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_catalog_attributes');
    }
};
