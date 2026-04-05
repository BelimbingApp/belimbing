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
     *
     * Creates the append-only turn event log. Each row is one immutable
     * event in the ordered stream a client subscribes to via SSE.
     *
     * `seq` is application-assigned per turn, strictly increasing, and
     * enforced via unique constraint on (turn_id, seq). The SSE resume
     * contract uses `after_seq` to replay missed events on reconnect.
     */
    public function up(): void
    {
        Schema::create('ai_chat_turn_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('turn_id');
            $table->unsignedBigInteger('seq');
            $table->string('event_type', 60);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['turn_id', 'seq']);
            $table->index('event_type');

            $table->foreign('turn_id')
                ->references('id')
                ->on('ai_chat_turns')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_chat_turn_events');
    }
};
