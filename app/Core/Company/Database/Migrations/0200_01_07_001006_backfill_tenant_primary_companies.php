<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->lockBackfillInputs();

        $assignments = [];
        $ambiguous = [];

        $tenants = DB::table('tenants')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'is_platform_operator']);

        foreach ($tenants as $tenant) {
            $existingAssignment = DB::table('tenant_primary_companies')
                ->where('tenant_id', $tenant->id)
                ->first();

            if ($existingAssignment !== null) {
                $designatedCompanyIsLive = DB::table('companies')
                    ->where('id', $existingAssignment->company_id)
                    ->where('tenant_id', $tenant->id)
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $designatedCompanyIsLive) {
                    throw new RuntimeException(
                        'Cannot preserve the designated primary company '.$existingAssignment->company_id
                        .' for tenant '.$tenant->id.' because it is missing, soft-deleted, or belongs to another tenant.'
                    );
                }

                continue;
            }

            $candidates = DB::table('companies')
                ->where('tenant_id', $tenant->id)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            // Company ID 1 is migration input only. Under the old model it
            // unambiguously represented the operator company. A deleted or
            // cross-tenant legacy row is corruption, not permission to guess.
            if ((bool) $tenant->is_platform_operator) {
                $legacyOperatorCompany = DB::table('companies')->where('id', 1)->first([
                    'tenant_id',
                    'deleted_at',
                ]);

                if ($legacyOperatorCompany !== null && (int) $legacyOperatorCompany->tenant_id !== (int) $tenant->id) {
                    throw new RuntimeException(
                        'Cannot backfill the platform-operator primary company because legacy company id 1 belongs to tenant '
                        .$legacyOperatorCompany->tenant_id.' instead of operator tenant '.$tenant->id.'. Repair the tenant assignment before retrying.'
                    );
                }

                if ($legacyOperatorCompany?->deleted_at !== null) {
                    throw new RuntimeException(
                        'Cannot backfill the platform-operator primary company because legacy company id 1 is soft-deleted. Restore it before retrying.'
                    );
                }

                if ($legacyOperatorCompany !== null) {
                    $assignments[(int) $tenant->id] = 1;

                    continue;
                }
            }

            if (count($candidates) === 1) {
                $assignments[(int) $tenant->id] = $candidates[0];
            } elseif (count($candidates) > 1) {
                $ambiguous[(int) $tenant->id] = $candidates;
            }
        }

        if ($ambiguous !== []) {
            $details = collect($ambiguous)
                ->map(fn (array $companyIds, int|string $tenantId): string => 'tenant '
                    .$tenantId.' has candidates ['.implode(', ', $companyIds).']')
                ->implode('; ');

            throw new RuntimeException(
                'Cannot backfill primary companies because the selection is ambiguous: '
                .$details.'. Insert an explicit same-tenant relationship into tenant_primary_companies, or repair the candidate data, then retry.'
            );
        }

        foreach ($assignments as $tenantId => $companyId) {
            DB::table('tenant_primary_companies')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
            ]);
        }
    }

    public function down(): void
    {
        // Backfilled rows are indistinguishable from assignments created or
        // transferred after deployment. The preceding schema migration owns
        // dropping the relationship table during a full rollback.
    }

    private function lockBackfillInputs(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'LOCK TABLE tenants, companies, tenant_primary_companies IN SHARE ROW EXCLUSIVE MODE'
            );
        }
    }
};
