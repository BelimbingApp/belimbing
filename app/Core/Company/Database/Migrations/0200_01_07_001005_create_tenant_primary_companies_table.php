<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_primary_companies', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->primary();
            $table->unsignedBigInteger('company_id')->unique();

            $table->foreign('tenant_id', 'tenant_primary_companies_tenant_foreign')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();
            $table->foreign(['company_id', 'tenant_id'], 'tenant_primary_companies_company_tenant_foreign')
                ->references(['id', 'tenant_id'])
                ->on('companies')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_primary_companies');
    }
};
