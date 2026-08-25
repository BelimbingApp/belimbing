<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL does not create backing indexes for foreign keys, so the
     * `users.company_id` and `users.employee_id` foreign keys need indexes for
     * affiliation lookups and referenced-row delete checks.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->index('company_id');
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['company_id']);
            $table->dropIndex(['employee_id']);
        });
    }
};
