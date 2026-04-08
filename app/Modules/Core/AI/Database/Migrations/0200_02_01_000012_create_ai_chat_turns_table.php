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
     * Creates the user-visible turn ledger. Each row represents one user
     * prompt and everything the agent does in response — the unit of live
     * observability in the coding-agent console UX.
     */
    public function up(): void
    {
        Schema::create('ai_chat_turns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('session_id');
            $table->foreignId('acting_for_user_id')->nullable()->constrained('users');
            $table->string('status', 20)->default('queued');
            $table->string('current_phase', 30)->default('waiting_for_worker');
            $table->string('current_label')->nullable();
            $table->unsignedBigInteger('last_event_seq')->default(0);
            $table->string('current_run_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->json('runtime_meta')->nullable();
            $table->timestamps();

            $table->index('session_id');
            $table->index('employee_id');
            $table->index('acting_for_user_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_chat_turns');
    }
};
