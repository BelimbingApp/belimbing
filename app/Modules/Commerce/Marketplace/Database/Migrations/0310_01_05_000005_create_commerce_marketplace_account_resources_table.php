<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_marketplace_account_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('channel');
            $table->string('marketplace_id');
            $table->string('kind');
            $table->string('external_id');
            $table->string('name');
            $table->string('status')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('imported_at');
            $table->timestamps();

            $table->unique([
                'company_id',
                'channel',
                'marketplace_id',
                'kind',
                'external_id',
            ], 'commerce_marketplace_account_resources_unique');
            $table->index(['company_id', 'channel', 'marketplace_id', 'kind'], 'commerce_marketplace_account_resources_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_marketplace_account_resources');
    }
};
