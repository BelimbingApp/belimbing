<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds user_id column and FK to company_external_accesses. User module
     * owns all references to users; Company migrations (01_11) run before
     * User (01_20), so this runs after users table exists.
     */
    public function up(): void
    {
        Schema::table('company_external_accesses', function (Blueprint $table): void {
            $table
                ->foreignId('user_id')
                ->nullable()
                ->after('relationship_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['user_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_external_accesses', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'is_active']);
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
