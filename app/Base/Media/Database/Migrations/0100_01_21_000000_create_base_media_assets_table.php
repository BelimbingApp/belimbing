<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_media_assets', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('base_media_assets')
                ->cascadeOnDelete();

            $table->string('disk');
            $table->string('storage_key');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('kind')->index();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['parent_id', 'kind']);
            $table->index(['disk', 'storage_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('base_media_assets');
    }
};
