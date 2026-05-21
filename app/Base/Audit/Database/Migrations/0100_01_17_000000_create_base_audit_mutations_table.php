<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_audit_mutations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('actor_type', 40)->index();
            $table->unsignedBigInteger('actor_id')->index();
            $table->string('actor_role', 100)->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('url')->nullable();
            $table->string('user_agent', 80)->nullable();
            $table->string('auditable_type')->index();
            $table->unsignedBigInteger('auditable_id')->index();
            $table->string('subject_name')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_identifier')->nullable();
            $table->string('source', 20)->default('listener')->index();
            $table->string('event', 20)->index();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('trace_id', 12)->nullable()->index();
            $table->timestamp('occurred_at')->index();

            $table->index(['auditable_type', 'auditable_id', 'occurred_at'], 'base_audit_mutations_auditable_occurred_index');
            $table->index(['actor_type', 'actor_id', 'occurred_at']);
        });

        DB::statement('CREATE INDEX base_audit_mutations_subject_idx ON base_audit_mutations (subject_name, subject_id, subject_identifier, occurred_at) WHERE subject_name IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('base_audit_mutations');
    }
};
