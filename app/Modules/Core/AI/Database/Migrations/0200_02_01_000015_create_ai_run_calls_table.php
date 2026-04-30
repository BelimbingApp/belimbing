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
     * One row per LLM call. A run can be a multi-call tool loop, so each
     * iteration's `usage` chunk lands here rather than overwriting a single
     * slot on `ai_runs`. The parent run's aggregate columns summarize these rows.
     */
    public function up(): void
    {
        Schema::create('ai_run_calls', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('run_id');
            $table->unsignedInteger('attempt_index');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('finish_reason', 40)->nullable();
            $table->string('native_finish_reason', 40)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('cached_input_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->json('raw_usage')->nullable();
            $table->string('pricing_source', 64)->nullable();
            $table->string('pricing_version', 64)->nullable();
            $table->unsignedInteger('cost_input_cents')->nullable();
            $table->unsignedInteger('cost_cached_input_cents')->nullable();
            $table->unsignedInteger('cost_output_cents')->nullable();
            $table->unsignedInteger('cost_total_cents')->nullable();
            $table->unsignedInteger('rate_limit_remaining_requests')->nullable();
            $table->unsignedBigInteger('rate_limit_remaining_tokens')->nullable();
            $table->timestamp('rate_limit_reset_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'attempt_index']);
            $table->index('run_id');
            $table->index('model');
            $table->index('provider');
            $table->index('created_at');

            $table->foreign('run_id')
                ->references('id')
                ->on('ai_runs')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_run_calls');
    }
};
