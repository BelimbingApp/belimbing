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
        Schema::create('ai_schedule_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->comment('Target agent');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users');
            $table->string('description');
            $table->text('execution_payload')->comment('Task text or command to run');
            $table->string('cron_expression', 100);
            $table->string('timezone', 60)->default('UTC');
            $table->boolean('is_enabled')->default(true);
            $table->string('concurrency_policy', 20)->default('skip')->comment('skip, allow, queue');
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'is_enabled']);
            $table->index('next_due_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_schedule_definitions');
    }
};
