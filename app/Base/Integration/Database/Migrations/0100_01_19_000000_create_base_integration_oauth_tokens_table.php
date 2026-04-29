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
        if (Schema::hasTable('base_external_oauth_tokens') && ! Schema::hasTable('base_integration_oauth_tokens')) {
            Schema::rename('base_external_oauth_tokens', 'base_integration_oauth_tokens');

            return;
        }

        Schema::create('base_integration_oauth_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('provider');
            $table->string('account_key')->default('default');
            $table->string('scope_type', 50)->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'account_key', 'scope_type', 'scope_id'], 'base_integration_oauth_tokens_owner_unique');
            $table->index(['provider', 'scope_type', 'scope_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_integration_oauth_tokens');
        Schema::dropIfExists('base_external_oauth_tokens');
    }
};
