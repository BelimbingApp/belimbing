<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('base_media_attachments', function (Blueprint $table): void {
            $table->timestamp('retained_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('base_media_attachments', function (Blueprint $table): void {
            $table->dropColumn('retained_at');
        });
    }
};
