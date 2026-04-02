<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_channel_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('channel', 30);
            $table->string('label');
            $table->text('credentials')->nullable()->comment('Encrypted JSON');
            $table->boolean('is_enabled')->default(true);
            $table->json('config')->nullable()->comment('Channel-specific settings');
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees');
            $table->timestamps();

            $table->unique(['company_id', 'channel', 'label']);
            $table->index(['company_id', 'channel', 'is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_channel_accounts');
    }
};
