<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('base_authz_decision_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('base_authz_decision_logs', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
