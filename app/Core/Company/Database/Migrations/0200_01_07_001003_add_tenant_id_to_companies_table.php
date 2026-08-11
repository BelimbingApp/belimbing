<?php

use App\Base\Tenancy\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Historical behavior only: this released migration backfilled companies
     * to the then-semantic tenant ID 1. Additive successor migrations mark the
     * operator explicitly, remove this default, and establish primary-company
     * ownership without changing retained IDs. Runtime code must not infer a
     * tenant or company role from ID 1.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->foreignId('tenant_id')
                ->default(Tenant::LICENSEE_TENANT_ID)
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
