<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->string('user_agent', 512)->nullable();
            $table->string('auditable_type')->index();
            $table->unsignedBigInteger('auditable_id')->index();
            $table->string('event', 20)->index();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->uuid('correlation_id')->nullable()->index();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id', 'occurred_at'], 'base_audit_mutations_auditable_occurred_index');
            $table->index(['actor_type', 'actor_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_audit_mutations');
    }
};
