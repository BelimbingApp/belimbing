<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 0001_01_01_000001_create_base_database_seeders_table runs right after
     * the table registry — both are self-preserving infrastructure.
     */
    public function up(): void
    {
        Schema::create('base_database_seeders', function (Blueprint $table) {
            $table->id();
            $table->string('seeder_class')->unique(); // Fully qualified class name
            $table->string('module_name')->nullable()->index(); // e.g., 'Geonames'
            $table->string('module_path')->nullable()->index(); // e.g., 'app/Modules/Core/Geonames'
            $table->string('migration_file')->nullable()->index(); // Migration that registered this seeder
            $table->string('status', 20)->default('pending')->index(); // pending, running, completed, failed, skipped
            $table->timestamp('ran_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Indexes for common queries
            $table->index(['status', 'migration_file']);
            $table->index(['module_name', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('base_database_seeders');
    }
};
