<?php

use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use RegistersTables;

    public function up(): void
    {
        Schema::create('base_workflow_transition_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('event_type');
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->string('lease_token', 64)->nullable()->unique();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['delivered_at', 'available_at'], 'base_workflow_outbox_due_idx');
            $table->index('lease_expires_at', 'base_workflow_outbox_lease_idx');
        });

        Schema::create('base_workflow_process_definition_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('definition_key');
            $table->unsignedInteger('definition_version');
            $table->char('definition_fingerprint', 64);
            $table->timestamps();

            $table->unique(
                ['definition_key', 'definition_version'],
                'base_workflow_process_definition_version_unique'
            );
        });

        Schema::create('base_workflow_process_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('definition_key');
            $table->unsignedInteger('definition_version');
            $table->char('definition_fingerprint', 64);
            $table->string('status');
            $table->integer('priority')->default(0);
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('correlation_key')->nullable();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('available_at');
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->text('pause_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['definition_key', 'definition_version'], 'base_workflow_process_definition_idx');
            $table->index(['status', 'available_at', 'priority'], 'base_workflow_process_status_idx');
            $table->index(['subject_type', 'subject_id'], 'base_workflow_process_subject_idx');
            $table->index('correlation_key', 'base_workflow_process_correlation_idx');
        });

        Schema::create('base_workflow_process_work_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('process_run_id')
                ->constrained('base_workflow_process_runs')
                ->cascadeOnDelete();
            $table->string('step_key');
            $table->string('label');
            $table->string('executor_key');
            $table->string('status');
            $table->string('dependency_mode', 3)->default('all');
            $table->string('required_signal')->nullable();
            $table->timestamp('signalled_at')->nullable();
            $table->json('signal_payload')->nullable();
            $table->unsignedInteger('delay_seconds')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(1);
            $table->integer('priority')->default(0);
            $table->string('lease_owner')->nullable();
            $table->string('lease_token', 64)->nullable()->unique();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->string('outcome')->nullable();
            $table->json('input')->nullable();
            $table->string('input_ref')->nullable();
            $table->json('output')->nullable();
            $table->string('result_ref')->nullable();
            $table->json('metadata')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['process_run_id', 'step_key'], 'base_workflow_process_step_unique');
            $table->index(['status', 'available_at'], 'base_workflow_work_available_idx');
            $table->index(['status', 'priority', 'available_at'], 'base_workflow_work_priority_idx');
            $table->index('lease_expires_at', 'base_workflow_work_lease_idx');
        });

        Schema::create('base_workflow_process_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_item_id')
                ->constrained('base_workflow_process_work_items')
                ->cascadeOnDelete();
            $table->foreignId('depends_on_work_item_id')
                ->constrained('base_workflow_process_work_items')
                ->cascadeOnDelete();
            $table->json('acceptable_outcomes');
            $table->timestamps();

            $table->unique(
                ['work_item_id', 'depends_on_work_item_id'],
                'base_workflow_process_dependency_unique'
            );
        });

        Schema::create('base_workflow_process_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('process_run_id')
                ->constrained('base_workflow_process_runs')
                ->cascadeOnDelete();
            $table->foreignId('work_item_id')
                ->nullable()
                ->constrained('base_workflow_process_work_items')
                ->nullOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('type');
            $table->json('payload')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['process_run_id', 'sequence'], 'base_workflow_process_event_sequence_unique');
            $table->index(['process_run_id', 'occurred_at'], 'base_workflow_process_event_timeline_idx');
        });

        foreach ($this->tables() as $table) {
            $this->registerTable($table);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables()) as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        return [
            'base_workflow_transition_outbox',
            'base_workflow_process_definition_versions',
            'base_workflow_process_runs',
            'base_workflow_process_work_items',
            'base_workflow_process_dependencies',
            'base_workflow_process_events',
        ];
    }
};
