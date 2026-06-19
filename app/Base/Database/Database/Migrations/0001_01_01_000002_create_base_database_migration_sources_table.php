<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records source fingerprints for applied migrations whose source state
     * matters after deployment, especially incubating schema allowed to remain
     * on a production database.
     */
    public function up(): void
    {
        Schema::create('base_database_migration_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('migration_name')->unique();
            $table->string('migration_file')->index();
            $table->string('relative_path')->index();
            $table->string('source_sha256', 64);
            $table->string('source_state', 20)->default('stable')->index();
            $table->timestamp('first_observed_at')->nullable();
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('base_database_migration_sources');
    }
};
