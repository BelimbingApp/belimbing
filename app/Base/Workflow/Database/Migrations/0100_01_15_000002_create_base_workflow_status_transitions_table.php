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
        Schema::create('base_workflow_status_transitions', function (Blueprint $table): void {
            $table->id();
            $table->string('flow');
            $table->string('from_code');
            $table->string('to_code');
            $table->string('label')->nullable();
            $table->string('capability')->nullable();
            $table->string('guard_class')->nullable();
            $table->string('action_class')->nullable();
            $table->unsignedInteger('sla_seconds')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['flow', 'from_code', 'to_code']);
            $table->index(['flow', 'from_code', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('base_workflow_status_transitions');
    }
};
