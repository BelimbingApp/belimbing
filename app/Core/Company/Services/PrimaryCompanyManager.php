<?php

namespace App\Core\Company\Services;

use App\Base\Tenancy\Models\Tenant;
use App\Core\Company\Exceptions\PrimaryCompanyAssignmentException;
use App\Core\Company\Exceptions\PrimaryCompanyInvariantViolationException;
use App\Core\Company\Exceptions\PrimaryCompanyNotProvisionedException;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\TenantPrimaryCompany;
use Illuminate\Support\Facades\DB;

class PrimaryCompanyManager
{
    public function platformOperatorCompany(): Company
    {
        return $this->requireForTenant(Tenant::requirePlatformOperator());
    }

    public function findForTenant(Tenant|int $tenant): ?Company
    {
        $tenant = $this->resolveTenant($tenant);
        $company = Company::withTrashed()
            ->join('tenant_primary_companies as primary_assignment', function ($join): void {
                $join->on('primary_assignment.company_id', '=', 'companies.id')
                    ->on('primary_assignment.tenant_id', '=', 'companies.tenant_id');
            })
            ->where('primary_assignment.tenant_id', $tenant->id)
            ->select('companies.*')
            ->first();

        if ($company === null) {
            $assignment = TenantPrimaryCompany::query()->whereKey($tenant->id)->first();

            if ($assignment === null) {
                return null;
            }

            throw new PrimaryCompanyInvariantViolationException(
                'The primary-company relationship references a company that does not exist or belongs to another tenant.',
                ['tenant_id' => (int) $tenant->id, 'company_id' => (int) $assignment->company_id],
            );
        }

        if ($company->trashed()) {
            throw new PrimaryCompanyInvariantViolationException(
                'The tenant primary company is soft-deleted.',
                ['tenant_id' => (int) $tenant->id, 'company_id' => (int) $company->id],
            );
        }

        return $company;
    }

    public function requireForTenant(Tenant|int $tenant): Company
    {
        $tenant = $this->resolveTenant($tenant);

        return $this->findForTenant($tenant)
            ?? throw new PrimaryCompanyNotProvisionedException((int) $tenant->id);
    }

    public function isPrimary(Company $company): bool
    {
        if ($company->trashed()) {
            throw new PrimaryCompanyInvariantViolationException(
                'A soft-deleted company cannot be treated as a valid primary company.',
                ['company_id' => (int) $company->id, 'tenant_id' => (int) $company->tenant_id],
            );
        }

        return TenantPrimaryCompany::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->exists();
    }

    public function assign(Tenant $tenant, Company $company): void
    {
        $this->writeAssignment($tenant, $company, false);
    }

    public function transfer(Tenant $tenant, Company $company): void
    {
        $this->writeAssignment($tenant, $company, true);
    }

    /**
     * @param  array<string, mixed>  $tenantAttributes
     * @param  array<string, mixed>  $companyAttributes
     */
    public function provisionTenant(array $tenantAttributes, array $companyAttributes): Tenant
    {
        return DB::transaction(function () use ($tenantAttributes, $companyAttributes): Tenant {
            $tenant = Tenant::query()->create($tenantAttributes);
            $company = Company::query()->create([
                ...$companyAttributes,
                'tenant_id' => $tenant->id,
            ]);
            $this->assign($tenant, $company);

            return $tenant;
        });
    }

    private function writeAssignment(Tenant $tenant, Company $company, bool $allowTransfer): void
    {
        DB::transaction(function () use ($tenant, $company, $allowTransfer): void {
            $lockedTenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();

            if ($lockedTenant === null) {
                throw new PrimaryCompanyAssignmentException(
                    'The tenant does not exist or is soft-deleted.',
                    ['tenant_id' => (int) $tenant->id],
                );
            }

            $assignment = TenantPrimaryCompany::query()
                ->whereKey($lockedTenant->id)
                ->lockForUpdate()
                ->first();
            $lockedCompany = Company::withTrashed()
                ->whereKey($company->id)
                ->lockForUpdate()
                ->first();

            if ($lockedCompany === null) {
                throw new PrimaryCompanyAssignmentException(
                    'The selected primary company does not exist.',
                    ['tenant_id' => (int) $lockedTenant->id, 'company_id' => (int) $company->id],
                );
            }

            if ($lockedCompany->trashed()) {
                throw new PrimaryCompanyAssignmentException(
                    'A soft-deleted company cannot be assigned as a tenant primary company.',
                    ['tenant_id' => (int) $lockedTenant->id, 'company_id' => (int) $lockedCompany->id],
                );
            }

            if ((int) $lockedCompany->tenant_id !== (int) $lockedTenant->id) {
                throw new PrimaryCompanyAssignmentException(
                    'A company cannot be assigned as the primary company of a different tenant.',
                    [
                        'tenant_id' => (int) $lockedTenant->id,
                        'company_id' => (int) $lockedCompany->id,
                        'company_tenant_id' => (int) $lockedCompany->tenant_id,
                    ],
                );
            }

            $otherAssignment = TenantPrimaryCompany::query()
                ->where('company_id', $lockedCompany->id)
                ->where('tenant_id', '!=', $lockedTenant->id)
                ->first();

            if ($otherAssignment !== null) {
                throw new PrimaryCompanyAssignmentException(
                    'A company cannot be primary for more than one tenant.',
                    ['company_id' => (int) $lockedCompany->id, 'tenant_id' => (int) $otherAssignment->tenant_id],
                );
            }

            if ($assignment === null) {
                TenantPrimaryCompany::query()->create([
                    'tenant_id' => $lockedTenant->id,
                    'company_id' => $lockedCompany->id,
                ]);

                return;
            }

            if ((int) $assignment->company_id === (int) $lockedCompany->id) {
                return;
            }

            if (! $allowTransfer) {
                throw new PrimaryCompanyAssignmentException(
                    'The tenant already has a primary company; use an explicit transfer.',
                    [
                        'tenant_id' => (int) $lockedTenant->id,
                        'primary_company_id' => (int) $assignment->company_id,
                    ],
                );
            }

            $assignment->company_id = $lockedCompany->id;
            $assignment->save();
        });
    }

    private function resolveTenant(Tenant|int $tenant): Tenant
    {
        $tenantId = $tenant instanceof Tenant ? (int) $tenant->id : $tenant;
        $resolved = Tenant::withTrashed()->find($tenantId);

        if ($resolved === null) {
            throw new PrimaryCompanyInvariantViolationException(
                'The referenced tenant does not exist.',
                ['tenant_id' => $tenantId],
            );
        }

        if ($resolved->trashed()) {
            throw new PrimaryCompanyInvariantViolationException(
                'The referenced tenant is soft-deleted.',
                ['tenant_id' => $tenantId],
            );
        }

        return $resolved;
    }
}
