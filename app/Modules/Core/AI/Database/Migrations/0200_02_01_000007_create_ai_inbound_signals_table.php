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
        Schema::create('ai_inbound_signals', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 30);
            $table->foreignId('channel_account_id')->nullable()->constrained('ai_channel_accounts');
            $table->string('authenticity_status', 20)->default('skipped');
            $table->string('sender_identifier')->nullable();
            $table->string('conversation_identifier')->nullable();
            $table->text('normalized_content')->nullable();
            $table->json('normalized_payload')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('resulting_operation_id')->nullable()->comment('Links to ai_operation_dispatches if work was created');
            $table->timestamp('received_at');
            $table->timestamp('routed_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'channel_account_id']);
            $table->index('received_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_inbound_signals');
    }
};
