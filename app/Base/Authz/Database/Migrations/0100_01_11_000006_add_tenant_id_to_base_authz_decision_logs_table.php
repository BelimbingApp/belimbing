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
        Schema::table('base_authz_decision_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->index()->after('company_id');
        });

        // Historical backfill only: every row here predates tenancy, same policy
        // as base_audit_actions / base_audit_mutations (#873 / #894). Runtime
        // resolveTenantId() can still leave null when there is no ambient tenant
        // and the actor company cannot be resolved — those rare rows stay null
        // and remain invisible to ordinary tenant admins (exact tenant_id match).
        DB::table('base_authz_decision_logs')
            ->whereNull('tenant_id')
            ->update(['tenant_id' => Tenant::LICENSEE_TENANT_ID]);
    }

    public function down(): void
    {
        Schema::table('base_authz_decision_logs', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
