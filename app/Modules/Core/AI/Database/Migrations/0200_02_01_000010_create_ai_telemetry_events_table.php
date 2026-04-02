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
        Schema::create('ai_telemetry_events', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('event_type', 60)->index();
            $table->string('run_id')->nullable()->index();
            $table->string('session_id')->nullable()->index();
            $table->string('dispatch_id')->nullable()->index();
            $table->foreignId('employee_id')->nullable()->constrained('employees');
            $table->string('target_type', 40)->nullable();
            $table->string('target_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['employee_id', 'event_type']);
            $table->index(['target_type', 'target_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_telemetry_events');
    }
};
