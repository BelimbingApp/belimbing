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
     */
    public function up(): void
    {
        Schema::create('base_workflow_status_history', function (Blueprint $table): void {
            $table->id();
            $table->string('flow');
            $table->unsignedBigInteger('flow_id');
            $table->string('status');
            $table->unsignedInteger('tat')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_role')->nullable();
            $table->string('actor_department')->nullable();
            $table->string('actor_company')->nullable();
            $table->json('assignees')->nullable();
            $table->text('comment')->nullable();
            $table->string('comment_tag')->nullable();
            $table->json('attachments')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('transitioned_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['flow', 'flow_id', 'transitioned_at'], 'idx_flow_lookup');
            $table->index(['flow', 'status', 'transitioned_at'], 'idx_flow_status');
            $table->index(['flow', 'status', 'tat'], 'idx_tat_sla');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('base_workflow_status_history');
    }
};
