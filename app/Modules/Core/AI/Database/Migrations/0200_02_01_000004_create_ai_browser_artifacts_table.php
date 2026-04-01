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
        Schema::create('ai_browser_artifacts', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('browser_session_id', 64);
            $table->string('type', 30);
            $table->string('storage_path', 512);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes')->default(0);
            $table->string('related_url', 2048)->nullable();
            $table->string('related_tab_id', 64)->nullable();
            $table->timestamps();

            $table->foreign('browser_session_id')
                ->references('id')
                ->on('ai_browser_sessions')
                ->cascadeOnDelete();

            $table->index('browser_session_id');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_browser_artifacts');
    }
};
