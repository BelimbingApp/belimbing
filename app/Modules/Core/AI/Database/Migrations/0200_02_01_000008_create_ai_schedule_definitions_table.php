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
     */
    public function up(): void
    {
        Schema::create('ai_schedule_definitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->comment('Target agent');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users');
            $table->string('source', 80)->default('core-ai')->comment('Owning module or subsystem');
            $table->string('source_key', 120)->nullable()->comment('Owner-facing stable key');
            $table->string('executor', 30)->default('agentic_runtime')->comment('agentic_runtime, headless_cli');
            $table->string('headless_provider', 60)->nullable();
            $table->string('headless_model', 120)->nullable();
            $table->string('description');
            $table->text('execution_payload')->comment('Task text or command to run');
            $table->string('cron_expression', 100);
            $table->string('timezone', 60)->default('UTC');
            $table->boolean('is_enabled')->default(true);
            $table->string('concurrency_policy', 20)->default('skip')->comment('skip, allow, queue');
            $table->timestamp('run_requested_at')->nullable()->comment('Manual run request claimed by planner');
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'is_enabled']);
            $table->index(['source', 'source_key']);
            $table->index(['executor', 'is_enabled']);
            $table->index('run_requested_at');
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
