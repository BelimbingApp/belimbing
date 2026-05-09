<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 0001_01_01_000000_create_base_database_tables_table runs before all
     * other migrations — the table registry must exist first so all subsequent
     * migrations can register their tables.
     */
    public function up(): void
    {
        Schema::create('base_database_tables', function (Blueprint $table) {
            $table->id();
            $table->string('table_name')->unique();
            $table->string('module_name')->nullable()->index();
            $table->string('module_path')->nullable()->index();
            $table->string('migration_file')->nullable()->index();
            $table->boolean('is_stable')->default(true)->index();
            $table->timestamp('stabilized_at')->nullable();
            $table->unsignedBigInteger('stabilized_by')->nullable();
            $table->timestamps();

            // Indexes for common queries
            $table->index(['module_name', 'is_stable']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('base_database_tables');
    }
};
