<?php

use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use RegistersTables;

    public function up(): void
    {
        Schema::table('base_workflow_process_runs', function (Blueprint $table): void {
            $table->string('scope_type', 16)->default('unresolved')->after('id');
            $table->unsignedBigInteger('tenant_id')->nullable()->after('scope_type');
            $table->index(['scope_type', 'tenant_id'], 'base_workflow_process_scope_idx');
        });
        Schema::table('base_workflow_process_work_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->unsignedInteger('version')->default(1)->after('status');
            $table->index(['tenant_id', 'status'], 'base_workflow_work_tenant_status_idx');
        });
        Schema::table('base_workflow_process_dependencies', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->index('tenant_id', 'base_workflow_dependency_tenant_idx');
        });
        Schema::table('base_workflow_process_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->index(['tenant_id', 'occurred_at'], 'base_workflow_event_tenant_time_idx');
        });

        // Existing rows have no reliable owner evidence. Preserve them as
        // unresolved so tenant-facing APIs fail closed instead of guessing.
        DB::table('base_workflow_process_runs')->update(['scope_type' => 'unresolved']);

        Schema::create('base_workflow_human_action_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('idempotency_key');
            $table->char('intent_hash', 64);
            $table->string('action_key');
            $table->string('subject_type');
            $table->string('subject_id');
            $table->unsignedBigInteger('process_run_id')->nullable();
            $table->unsignedBigInteger('work_item_id')->nullable();
            $table->string('actor_type');
            $table->unsignedBigInteger('actor_id');
            $table->json('result')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key'], 'base_workflow_human_request_unique');
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'base_workflow_human_subject_idx');
        });

        $this->registerTable('base_workflow_human_action_requests');
    }

    public function down(): void
    {
        $this->unregisterTable('base_workflow_human_action_requests');
        Schema::dropIfExists('base_workflow_human_action_requests');
        Schema::table('base_workflow_process_events', fn (Blueprint $table) => $table->dropColumn('tenant_id'));
        Schema::table('base_workflow_process_dependencies', fn (Blueprint $table) => $table->dropColumn('tenant_id'));
        Schema::table('base_workflow_process_work_items', fn (Blueprint $table) => $table->dropColumn(['tenant_id', 'version']));
        Schema::table('base_workflow_process_runs', fn (Blueprint $table) => $table->dropColumn(['scope_type', 'tenant_id']));
    }
};
