<?php

use App\Base\Foundation\Compatibility\LegacyApplicationClassMap;
use App\Base\Tenancy\Models\Tenant;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->lockBackfillInputs();
        $assignments = $this->preflightAssignments();

        Schema::table('addresses', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->index('tenant_id', 'addresses_tenant_index');
            $table->foreign('tenant_id', 'addresses_tenant_foreign')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();
        });

        foreach ($assignments as $addressId => $tenantId) {
            DB::table('addresses')->where('id', $addressId)->update(['tenant_id' => $tenantId]);
        }

        Schema::table('addresses', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['tenant_id']);
            } else {
                $table->dropForeign('addresses_tenant_foreign');
            }

            $table->dropIndex('addresses_tenant_index');
            $table->dropColumn('tenant_id');
        });
    }

    /** @return array<int, int> */
    private function preflightAssignments(): array
    {
        $addressIds = DB::table('addresses')->orderBy('id')->pluck('id');

        if ($addressIds->isEmpty()) {
            return [];
        }

        $tenantIds = DB::table('tenants')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id);
        $assignments = [];
        $ambiguous = [];

        foreach ($addressIds as $addressId) {
            $linkedTenantIds = $this->linkedTenantIds((int) $addressId);

            if (count($linkedTenantIds) === 1) {
                $assignments[(int) $addressId] = $linkedTenantIds[0];

                continue;
            }

            if (count($linkedTenantIds) > 1) {
                $ambiguous[] = 'address '.$addressId.' is linked to tenants ['.implode(', ', $linkedTenantIds).']';

                continue;
            }

            if ($tenantIds->count() === 1) {
                $assignments[(int) $addressId] = $tenantIds->first();

                continue;
            }

            $ambiguous[] = 'address '.$addressId.' has no tenant-owned link';
        }

        if ($ambiguous !== []) {
            throw new RuntimeException(
                'Cannot backfill address tenancy: '.implode('; ', $ambiguous)
                .'. Assign each address to exactly one tenant-owned company or employee before retrying.'
            );
        }

        $missingTenantIds = collect($assignments)
            ->diff($tenantIds)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($missingTenantIds !== []) {
            throw new RuntimeException(
                'Cannot backfill address tenancy because linked records reference missing tenant IDs ['
                .implode(', ', $missingTenantIds).']. Repair those tenant assignments before retrying.'
            );
        }

        return $assignments;
    }

    /** @return list<int> */
    private function linkedTenantIds(int $addressId): array
    {
        $links = DB::table('addressables')
            ->where('address_id', $addressId)
            ->get(['addressable_type', 'addressable_id']);
        $tenantIds = [];

        foreach ($links as $link) {
            // Morph rows written before the four-root topology normalization
            // still carry pre-cutover class names. That normalization migration
            // sorts after this file, so a database catching up across both
            // releases in one migrate run reads the legacy names here first.
            $addressableType = LegacyApplicationClassMap::canonical($link->addressable_type);

            $tenantId = match ($addressableType) {
                Company::class => $this->companyTenantId((int) $link->addressable_id),
                Employee::class => $this->employeeTenantId((int) $link->addressable_id),
                default => throw new RuntimeException(
                    'Cannot backfill address '.$addressId.' because addressable type '
                    .$link->addressable_type.' has no tenant ownership mapping.'
                ),
            };

            if ($tenantId !== null) {
                $tenantIds[] = (int) $tenantId;
            }
        }

        sort($tenantIds);

        return array_values(array_unique($tenantIds));
    }

    private function companyTenantId(int $companyId): mixed
    {
        if ($this->companiesCarryTenantId()) {
            return DB::table('companies')->where('id', $companyId)->value('tenant_id');
        }

        return DB::table('companies')->where('id', $companyId)->exists()
            ? Tenant::LICENSEE_TENANT_ID
            : null;
    }

    private function employeeTenantId(int $employeeId): mixed
    {
        $employeeCompany = DB::table('employees')
            ->join('companies', 'companies.id', '=', 'employees.company_id')
            ->where('employees.id', $employeeId);

        if ($this->companiesCarryTenantId()) {
            return $employeeCompany->value('companies.tenant_id');
        }

        return $employeeCompany->exists() ? Tenant::LICENSEE_TENANT_ID : null;
    }

    /**
     * companies.tenant_id is added by 0200_01_07_001003, which sorts after this
     * file. A database upgrading across both releases in one migrate run has no
     * column to read yet; on that schema every company still belongs to the
     * legacy licensee tenant — the same value 0200_01_07_001003 subsequently
     * backfills as its column default.
     */
    private function companiesCarryTenantId(): bool
    {
        return Schema::hasColumn('companies', 'tenant_id');
    }

    private function lockBackfillInputs(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('LOCK TABLE tenants IN SHARE ROW EXCLUSIVE MODE');

        if (Schema::hasTable('companies')) {
            DB::statement('LOCK TABLE companies IN SHARE ROW EXCLUSIVE MODE');
        }

        if (Schema::hasTable('employees')) {
            DB::statement('LOCK TABLE employees IN SHARE ROW EXCLUSIVE MODE');
        }

        DB::statement('LOCK TABLE addresses, addressables IN SHARE ROW EXCLUSIVE MODE');
    }
};
