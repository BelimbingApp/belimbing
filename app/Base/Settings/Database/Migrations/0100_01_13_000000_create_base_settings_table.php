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
        Schema::create('base_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->json('value');
            $table->boolean('is_encrypted')->default(false);
            $table->string('scope_type', 50)->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->timestamps();

            $table->unique(['key', 'scope_type', 'scope_id'], 'base_settings_key_scope_unique');
            $table->index(['scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_settings');
    }
};
