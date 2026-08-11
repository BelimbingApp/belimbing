<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->lockIntegrityInputs();

        $orphanTenantIds = DB::table('companies')
            ->leftJoin('tenants', 'tenants.id', '=', 'companies.tenant_id')
            ->whereNull('tenants.id')
            ->distinct()
            ->orderBy('companies.tenant_id')
            ->pluck('companies.tenant_id')
            ->all();

        if ($orphanTenantIds !== []) {
            throw new RuntimeException(
                'Cannot enforce company tenancy: companies reference missing tenant IDs ['
                .implode(', ', $orphanTenantIds).']. Repair those assignments before retrying.'
            );
        }

        $invalidParents = DB::table('companies as child')
            ->leftJoin('companies as parent', 'parent.id', '=', 'child.parent_id')
            ->whereNotNull('child.parent_id')
            ->where(function ($query): void {
                $query->whereNull('parent.id')
                    ->orWhereColumn('parent.tenant_id', '!=', 'child.tenant_id');
            })
            ->orderBy('child.id')
            ->get(['child.id', 'child.parent_id', 'child.tenant_id'])
            ->map(fn (object $row): string => "company {$row->id} → parent {$row->parent_id} (tenant {$row->tenant_id})")
            ->all();

        if ($invalidParents !== []) {
            throw new RuntimeException(
                'Cannot enforce tenant-safe company hierarchy: '.implode('; ', $invalidParents)
                .'. Reassign or clear those parents before retrying.'
            );
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->change();
            $table->unique(['id', 'tenant_id'], 'companies_id_tenant_unique');
            $table->foreign('tenant_id', 'companies_tenant_foreign')
                ->references('id')
                ->on('tenants')
                ->restrictOnDelete();
            $table->foreign(['parent_id', 'tenant_id'], 'companies_parent_tenant_foreign')
                ->references(['id', 'tenant_id'])
                ->on('companies')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropForeign('companies_parent_tenant_foreign');
            $table->dropForeign('companies_tenant_foreign');
            $table->dropUnique('companies_id_tenant_unique');
            $table->unsignedBigInteger('tenant_id')->default(1)->change();
        });
    }

    private function lockIntegrityInputs(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE tenants, companies IN SHARE ROW EXCLUSIVE MODE');
        }
    }
};
