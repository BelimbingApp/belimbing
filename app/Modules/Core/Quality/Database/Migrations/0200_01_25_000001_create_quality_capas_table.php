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
        Schema::create('quality_capas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ncr_id')->index()->constrained('quality_ncrs')->cascadeOnDelete();
            $table->string('workflow_status')->default('triage_pending');
            $table->text('triage_summary')->nullable();
            $table->string('triage_confidence')->nullable();
            $table->string('assigned_department')->nullable();
            $table->string('assigned_supplier_name')->nullable();
            $table->text('assignment_comment')->nullable();
            $table->timestamp('assignment_due_at')->nullable();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users');
            $table->timestamp('assigned_at')->nullable();
            $table->string('approval_state')->nullable(); // pending, approved, returned, not_justified
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('rework_reason')->nullable();
            $table->text('containment_action')->nullable();
            $table->text('correction')->nullable();
            $table->text('root_cause_occurred')->nullable();
            $table->text('root_cause_leakage')->nullable();
            $table->text('corrective_action_occurred')->nullable();
            $table->date('effective_date_occurred')->nullable();
            $table->text('corrective_action_leakage')->nullable();
            $table->date('effective_date_leakage')->nullable();
            $table->text('quality_review_comment')->nullable();
            $table->text('quality_feedback')->nullable();
            $table->string('verification_result')->nullable(); // effective, partially_effective, ineffective
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quality_capas');
    }
};
