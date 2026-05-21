<?php

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
        Schema::create('base_authz_decision_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('actor_type', 40)->index();
            $table->unsignedBigInteger('actor_id')->index();
            $table->unsignedBigInteger('acting_for_user_id')->nullable()->index();
            $table->string('capability')->index();
            $table->string('resource_type')->nullable()->index();
            $table->string('resource_id')->nullable()->index();
            $table->boolean('allowed')->index();
            $table->string('reason_code')->index();
            $table->json('applied_policies')->nullable();
            $table->json('context')->nullable();
            $table->string('trace_id', 12)->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['actor_type', 'actor_id', 'occurred_at']);
            $table->index(['capability', 'allowed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('base_authz_decision_logs');
    }
};
