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
        Schema::create('quality_evidence', function (Blueprint $table): void {
            $table->id();
            $table->string('evidenceable_type')->index();
            $table->unsignedBigInteger('evidenceable_id')->index();
            $table->string('evidence_type'); // original_complaint, department_support, occurrence_evidence, etc.
            $table->foreignId('media_asset_id')->constrained('base_media_assets')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users');
            $table->timestamp('uploaded_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['evidenceable_type', 'evidenceable_id', 'evidence_type'], 'quality_evidence_morph_type_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quality_evidence');
    }
};
