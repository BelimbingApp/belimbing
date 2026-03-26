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
        Schema::create('quality_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ncr_id')->nullable()->index()->constrained('quality_ncrs')->nullOnDelete();
            $table->foreignId('capa_id')->nullable()->index()->constrained('quality_capas')->nullOnDelete();
            $table->foreignId('scar_id')->nullable()->index()->constrained('quality_scars')->nullOnDelete();
            $table->string('event_type')->index(); // evidence_ingested, ai_artifact_accepted, knowledge_published
            $table->string('actor_type'); // user, system, ai
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quality_events');
    }
};
