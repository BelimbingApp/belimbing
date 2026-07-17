<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('base_workflow_status_history', function (Blueprint $table): void {
            $table->string('actor_type')->nullable()->after('actor_id');
        });
    }

    public function down(): void
    {
        Schema::table('base_workflow_status_history', function (Blueprint $table): void {
            $table->dropColumn('actor_type');
        });
    }
};
