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
        Schema::create('ai_browser_sessions', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('company_id')->constrained('companies');
            $table->string('status', 20)->default('opening');
            $table->boolean('headless')->default(true);
            $table->string('active_tab_id', 64)->nullable();
            $table->string('current_url', 2048)->nullable();
            $table->json('tabs')->nullable();
            $table->json('page_state')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'status']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_browser_sessions');
    }
};
