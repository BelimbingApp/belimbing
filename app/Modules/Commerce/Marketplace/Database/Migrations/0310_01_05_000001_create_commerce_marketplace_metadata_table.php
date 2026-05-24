<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_marketplace_metadata', function (Blueprint $table): void {
            $table->id();
            $table->string('channel');
            $table->string('environment');
            $table->string('marketplace_id')->nullable();
            $table->string('kind');
            $table->string('key');
            $table->json('payload');
            $table->string('etag')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'environment', 'marketplace_id', 'kind', 'key'], 'commerce_marketplace_metadata_unique');
            $table->index(['channel', 'environment', 'kind'], 'commerce_marketplace_metadata_lookup_index');
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_marketplace_metadata');
    }
};
