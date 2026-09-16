<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_media_attachments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('media_asset_id')->unique()->constrained('base_media_assets')->restrictOnDelete();
            $table->string('subject_type');
            $table->string('subject_id');
            $table->string('state')->index();
            $table->string('uploaded_by_type');
            $table->unsignedBigInteger('uploaded_by_id');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'media_attachments_tenant_subject_idx');
            $table->index(['tenant_id', 'state', 'created_at'], 'media_attachments_draft_cleanup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_media_attachments');
    }
};
