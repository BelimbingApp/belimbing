<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        Schema::create('base_database_data_share_transfer_offers', function (Blueprint $table): void {
            $table->id();
            $table->char('offer_id', 26)->unique();
            $table->char('secret_hash', 64);
            $table->text('secret');
            $table->unsignedBigInteger('published_by_actor_id')->nullable();
            $table->char('package_id', 26)->unique();
            $table->char('package_sha256', 64);
            $table->string('package_path');
            $table->string('source_instance_id');
            $table->string('source_name');
            $table->string('source_role', 20);
            $table->string('scope_name');
            $table->unsignedBigInteger('bytes');
            $table->json('metadata');
            $table->string('status', 20)->index();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('download_count')->default(0);
            $table->unsignedInteger('max_downloads')->nullable();
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamps();
            $table->index(['source_instance_id', 'scope_name']);
        });

        Schema::create('base_database_data_share_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('package_id')->unique();
            $table->char('package_sha256', 64);
            $table->string('package_path');
            $table->string('source_instance_id');
            $table->string('source_role', 20);
            $table->string('target_instance_id');
            $table->string('scope_name');
            $table->char('offer_id', 26)->index();
            $table->string('status', 20)->index();
            $table->timestamp('received_at');
            $table->timestamp('expires_at');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('base_database_data_share_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained('base_database_data_share_receipts')->cascadeOnDelete();
            $table->char('plan_hash', 64)->index();
            $table->char('package_sha256', 64);
            $table->char('destination_fingerprint', 64);
            $table->json('summary');
            $table->string('status', 20)->index();
            $table->timestamp('planned_at');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('base_database_data_share_plan_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('base_database_data_share_plans')->cascadeOnDelete();
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

        Schema::create('base_database_data_share_events', function (Blueprint $table): void {
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
        Schema::dropIfExists('base_database_data_share_events');
        Schema::dropIfExists('base_database_data_share_plan_actions');
        Schema::dropIfExists('base_database_data_share_plans');
        Schema::dropIfExists('base_database_data_share_receipts');
        Schema::dropIfExists('base_database_data_share_transfer_offers');
    }
};
