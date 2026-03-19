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
        Schema::create('base_workflow_status_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('flow')->index();
            $table->string('code');
            $table->string('label');
            $table->json('pic')->nullable();
            $table->json('notifications')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->json('comment_tags')->nullable();
            $table->text('prompt')->nullable();
            $table->string('kanban_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['flow', 'code']);
            $table->index(['flow', 'is_active', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('base_workflow_status_configs');
    }
};
