<?php

use App\Base\Tenancy\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['base_audit_mutations', 'base_audit_actions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('company_id');
            });

            // Every historic row predates tenancy, so it belongs to the
            // licensee tenant. Stays truthful when later tenants arrive and
            // audit history is filtered per tenant. Runs before Base/Tenancy
            // creates the tenants table, hence no FK — the licensee tenant
            // provisioner guarantees id=1 exists.
            DB::table($tableName)->whereNull('tenant_id')->update(['tenant_id' => Tenant::LICENSEE_TENANT_ID]);
        }
    }

    public function down(): void
    {
        foreach (['base_audit_mutations', 'base_audit_actions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
