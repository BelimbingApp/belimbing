<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds head_id foreign key to company_departments. Employee module
     * runs after Company (01_07), so this runs after employees table exists.
     */
    public function up(): void
    {
        Schema::table('company_departments', function (Blueprint $table): void {
            $table->foreign('head_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_departments', function (Blueprint $table): void {
            $table->dropForeign(['head_id']);
        });
    }
};
