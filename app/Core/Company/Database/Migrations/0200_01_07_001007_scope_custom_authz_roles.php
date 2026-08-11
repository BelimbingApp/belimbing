<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string CHECK_CONSTRAINT = 'base_authz_roles_custom_company_check';

    private const string INSERT_TRIGGER = 'base_authz_roles_custom_company_insert';

    private const string UPDATE_TRIGGER = 'base_authz_roles_custom_company_update';

    public function up(): void
    {
        if (! Schema::hasTable('base_authz_roles')) {
            return;
        }

        $this->lockBackfillInputs();

        $customGlobalRoleIds = DB::table('base_authz_roles')
            ->where('is_system', false)
            ->whereNull('company_id')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);

        $operator = null;
        $primaryCompany = null;

        if ($customGlobalRoleIds->isNotEmpty()) {
            $operator = DB::table('tenants')
                ->where('is_platform_operator', true)
                ->whereNull('deleted_at')
                ->first(['id']);
            $primaryCompany = $operator === null
                ? null
                : DB::table('tenant_primary_companies')
                    ->join('companies', function ($join): void {
                        $join->on('companies.id', '=', 'tenant_primary_companies.company_id')
                            ->on('companies.tenant_id', '=', 'tenant_primary_companies.tenant_id');
                    })
                    ->where('tenant_primary_companies.tenant_id', $operator->id)
                    ->whereNull('companies.deleted_at')
                    ->first(['companies.id']);

            if ($operator === null || $primaryCompany === null) {
                throw new RuntimeException(
                    'Cannot scope legacy custom global roles because the platform-operator tenant has no valid primary company. Provision or repair that relationship before retrying.'
                );
            }

        }

        $invalidAssignments = DB::table('base_authz_principal_roles as assignment')
            ->join('base_authz_roles as role', 'role.id', '=', 'assignment.role_id')
            ->leftJoin('companies as role_company', 'role_company.id', '=', 'role.company_id')
            ->leftJoin('companies as assignment_company', 'assignment_company.id', '=', 'assignment.company_id')
            ->where('role.is_system', false)
            ->orderBy('assignment.id')
            ->get([
                'assignment.id',
                'assignment.company_id as assignment_company_id',
                'assignment_company.tenant_id as assignment_tenant_id',
                'assignment_company.deleted_at as assignment_company_deleted_at',
                'role.id as role_id',
                'role.company_id as role_company_id',
                'role_company.tenant_id as role_tenant_id',
                'role_company.deleted_at as role_company_deleted_at',
            ])
            ->filter(function (object $assignment) use ($operator): bool {
                $roleTenantId = $assignment->role_company_id === null
                    ? $operator?->id
                    : $assignment->role_tenant_id;

                return $roleTenantId === null
                    || $assignment->assignment_company_id === null
                    || $assignment->assignment_tenant_id === null
                    || $assignment->assignment_company_deleted_at !== null
                    || ($assignment->role_company_id !== null && $assignment->role_company_deleted_at !== null)
                    || (int) $assignment->assignment_tenant_id !== (int) $roleTenantId;
            })
            ->map(fn (object $assignment): string => 'assignment '.$assignment->id
                .' (role '.$assignment->role_id.', company '
                .($assignment->assignment_company_id ?? 'null').')')
            ->all();

        if ($invalidAssignments !== []) {
            throw new RuntimeException(
                'Cannot scope custom roles because these assignments have no company or cross a tenant boundary: '
                .implode('; ', $invalidAssignments)
                .'. Assign each role only within its owning tenant before retrying.'
            );
        }

        if ($customGlobalRoleIds->isNotEmpty()) {
            DB::table('base_authz_roles')
                ->whereIn('id', $customGlobalRoleIds)
                ->update(['company_id' => $primaryCompany->id]);
        }

        $this->createCustomRoleCompanyConstraint();
    }

    public function down(): void
    {
        if (! Schema::hasTable('base_authz_roles')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE base_authz_roles DROP CONSTRAINT IF EXISTS '.self::CHECK_CONSTRAINT);

            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS '.self::INSERT_TRIGGER);
            DB::statement('DROP TRIGGER IF EXISTS '.self::UPDATE_TRIGGER);
        }

        // Backfilled role ownership remains explicit on rollback. Reverting it
        // to a deployment-global null would reintroduce cross-tenant access.
    }

    private function lockBackfillInputs(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'LOCK TABLE tenants, companies, tenant_primary_companies, base_authz_roles, base_authz_principal_roles IN SHARE ROW EXCLUSIVE MODE'
        );
    }

    private function createCustomRoleCompanyConstraint(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE base_authz_roles ADD CONSTRAINT '.self::CHECK_CONSTRAINT
                .' CHECK (is_system OR company_id IS NOT NULL)'
            );

            return;
        }

        if ($driver !== 'sqlite') {
            return;
        }

        DB::statement(
            'CREATE TRIGGER '.self::INSERT_TRIGGER
            .' BEFORE INSERT ON base_authz_roles'
            .' WHEN NEW.is_system = 0 AND NEW.company_id IS NULL'
            ." BEGIN SELECT RAISE(ABORT, 'Custom roles require an owning company'); END"
        );
        DB::statement(
            'CREATE TRIGGER '.self::UPDATE_TRIGGER
            .' BEFORE UPDATE ON base_authz_roles'
            .' WHEN NEW.is_system = 0 AND NEW.company_id IS NULL'
            ." BEGIN SELECT RAISE(ABORT, 'Custom roles require an owning company'); END"
        );
    }
};
