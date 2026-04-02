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
        Schema::create('ai_orchestration_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('parent_session_id')->nullable();
            $table->string('parent_run_id')->nullable();
            $table->string('parent_dispatch_id')->nullable();
            $table->foreignId('parent_employee_id')->constrained('employees');
            $table->foreignId('child_employee_id')->constrained('employees');
            $table->foreignId('acting_for_user_id')->nullable()->constrained('users');
            $table->text('task');
            $table->string('task_type', 60)->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('spawn_envelope')->nullable();
            $table->text('result_summary')->nullable();
            $table->json('result_meta')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['child_employee_id', 'status']);
            $table->index(['parent_employee_id', 'status']);
            $table->index('parent_session_id');
            $table->index('parent_dispatch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_orchestration_sessions');
    }
};
