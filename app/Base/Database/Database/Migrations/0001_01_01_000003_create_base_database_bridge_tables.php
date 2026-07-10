<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_database_bridge_receive_grants', function (Blueprint $table): void {
            $table->id();
            $table->char('grant_id', 26)->unique();
            $table->char('secret_hash', 64);
            $table->unsignedBigInteger('issued_by_actor_id')->nullable();
            $table->string('expected_source_instance_id');
            $table->string('expected_source_role', 20);
            $table->string('target_instance_id');
            $table->string('target_role', 20);
            $table->string('scope_name');
            $table->unsignedBigInteger('max_bytes');
            $table->string('status', 20)->index();
            $table->char('consumed_package_sha256', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['expected_source_instance_id', 'scope_name']);
        });

        Schema::create('base_database_bridge_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('package_id')->unique();
            $table->char('package_sha256', 64);
            $table->string('package_path');
            $table->string('source_instance_id');
            $table->string('source_role', 20);
            $table->string('target_instance_id');
            $table->string('scope_name');
            $table->foreignId('receive_grant_id')
                ->constrained('base_database_bridge_receive_grants')
                ->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->timestamp('received_at');
            $table->timestamp('expires_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('base_database_bridge_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained('base_database_bridge_receipts')->cascadeOnDelete();
            $table->char('plan_hash', 64)->index();
            $table->char('package_sha256', 64);
            $table->char('destination_fingerprint', 64);
            $table->json('summary');
            $table->string('status', 20)->index();
            $table->timestamp('planned_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('base_database_bridge_plan_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('base_database_bridge_plans')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('scope_name');
            $table->string('table_name');
            $table->char('primary_key_hash', 64);
            $table->json('primary_key');
            $table->string('action', 20)->index();
            $table->char('incoming_fingerprint', 64);
            $table->char('destination_fingerprint', 64)->nullable();
            $table->unique(['plan_id', 'sequence']);
            $table->index(['plan_id', 'table_name']);
        });

        Schema::create('base_database_bridge_events', function (Blueprint $table): void {
            $table->id();
            $table->string('package_id')->nullable()->index();
            $table->char('plan_hash', 64)->nullable();
            $table->string('action', 40)->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('source_instance_id')->nullable();
            $table->string('target_instance_id')->nullable();
            $table->string('scope_name')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_database_bridge_events');
        Schema::dropIfExists('base_database_bridge_plan_actions');
        Schema::dropIfExists('base_database_bridge_plans');
        Schema::dropIfExists('base_database_bridge_receipts');
        Schema::dropIfExists('base_database_bridge_receive_grants');
    }
};
