<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('base_database_tables', 'is_stable')) {
            return;
        }

        Schema::table('base_database_tables', function (Blueprint $table): void {
            $table->dropIndex('base_database_tables_module_name_is_stable_index');
            $table->dropIndex('base_database_tables_is_stable_index');
            $table->dropColumn('is_stable');
        });
    }

    public function down(): void
    {
        Schema::table('base_database_tables', function (Blueprint $table): void {
            $table->boolean('is_stable')->default(true);
            $table->index('is_stable', 'base_database_tables_is_stable_index');
            $table->index(['module_name', 'is_stable'], 'base_database_tables_module_name_is_stable_index');
        });
    }
};
