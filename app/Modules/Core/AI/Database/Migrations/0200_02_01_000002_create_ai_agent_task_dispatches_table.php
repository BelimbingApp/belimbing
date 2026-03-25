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
        Schema::create('ai_agent_task_dispatches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('acting_for_user_id')->constrained('users');
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->text('task');
            $table->string('status', 20)->default('queued');
            $table->string('run_id')->nullable();
            $table->text('result_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('it_tickets')->nullOnDelete();
            $table->index('status');
            $table->index(['employee_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_agent_task_dispatches');
    }
};
