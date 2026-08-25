<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL does not create backing indexes for foreign keys, so the
     * `users.company_id` and `users.employee_id` FKs from the create-users
     * migration have none: affiliation lookups scan, and deleting referenced
     * companies/employees rows scans `users` to enforce ON DELETE SET NULL.
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
