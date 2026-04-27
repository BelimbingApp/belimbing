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
        Schema::create('commerce_catalog_product_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->index()->constrained('companies');
            $table->foreignId('category_id')->nullable()->constrained('commerce_catalog_categories')->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_catalog_product_templates');
    }
};
