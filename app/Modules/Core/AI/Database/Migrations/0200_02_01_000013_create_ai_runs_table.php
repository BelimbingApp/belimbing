<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;

    /**
     * Run the migrations.
     *
     * Per-run aggregates (cached / reasoning / total tokens, cost cents, call_count)
     * summarize child `ai_run_calls` rows once that ledger is populated.
     */
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('session_id')->nullable();
            $table->foreignId('acting_for_user_id')->nullable()->constrained('users');
            $table->string('dispatch_id')->nullable();
            $table->string('source', 30)->default('chat');
            $table->string('execution_mode', 20)->default('interactive');
            $table->string('status', 20)->default('queued');
            $table->string('current_phase', 30)->nullable();
            $table->string('current_label')->nullable();
            $table->unsignedBigInteger('last_event_seq')->nullable()->default(0);
            $table->timestamp('cancel_requested_at')->nullable();
            $table->json('runtime_meta')->nullable();
            $table->string('provider_name')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('timeout_seconds')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('cached_input_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('cost_input_cents')->nullable();
            $table->unsignedInteger('cost_cached_input_cents')->nullable();
            $table->unsignedInteger('cost_output_cents')->nullable();
            $table->unsignedInteger('cost_total_cents')->nullable();
            $table->string('pricing_version', 64)->nullable();
            $table->unsignedInteger('call_count')->default(0);
            $table->json('retry_attempts')->nullable();
            $table->json('tool_actions')->nullable();
            $table->string('error_type', 40)->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('session_id');
            $table->index('dispatch_id');
            $table->index('employee_id');
            $table->index('acting_for_user_id');
            $table->index('status');
            $table->index('created_at');
        });

        Schema::table('ai_operation_dispatches', function (Blueprint $table): void {
            $table->foreign('run_id')
                ->references('id')
                ->on('ai_runs')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_operation_dispatches', function (Blueprint $table): void {
            $table->dropForeign(['run_id']);
        });

        Schema::dropIfExists('ai_runs');
    }
};
