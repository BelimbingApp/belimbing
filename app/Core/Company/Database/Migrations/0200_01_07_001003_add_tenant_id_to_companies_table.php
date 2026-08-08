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
     * Every existing company backfills to the licensee tenant (id=1), making
     * this a no-op for single-licensee instances. The tenants table is created
     * by Base/Tenancy (0100_01_25), which sorts before the Company block.
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
