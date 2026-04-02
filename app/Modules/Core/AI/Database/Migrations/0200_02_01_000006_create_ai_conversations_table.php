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
        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('channel', 30);
            $table->foreignId('channel_account_id')->nullable()->constrained('ai_channel_accounts');
            $table->string('external_id')->nullable()->comment('Platform conversation/thread ID');
            $table->json('participants')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('last_outbound_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'channel']);
            $table->index(['channel_account_id', 'external_id']);
        });

        Schema::create('ai_conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('direction', 10)->comment('inbound or outbound');
            $table->string('external_message_id')->nullable()->comment('Platform message ID');
            $table->text('content')->nullable();
            $table->json('media')->nullable()->comment('Attached media references');
            $table->json('raw_payload')->nullable()->comment('Original platform payload');
            $table->string('delivery_status', 30)->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'direction']);
            $table->index('delivery_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
