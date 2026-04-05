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
        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('session_id')->nullable();
            $table->foreignId('acting_for_user_id')->nullable()->constrained('users');
            $table->string('dispatch_id')->nullable();
            $table->ulid('turn_id')->nullable();
            $table->string('source', 30);
            $table->string('execution_mode', 20);
            $table->string('status', 20)->default('running');
            $table->string('provider_name')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('timeout_seconds')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->json('retry_attempts')->nullable();
            $table->json('fallback_attempts')->nullable();
            $table->json('tool_actions')->nullable();
            $table->string('error_type', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('session_id');
            $table->index('dispatch_id');
            $table->index('turn_id');
            $table->index('employee_id');
            $table->index('acting_for_user_id');
            $table->index('status');
            $table->index('created_at');

            $table->foreign('turn_id')
                ->references('id')
                ->on('ai_chat_turns')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
