<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string OPERATOR_INDEX = 'tenants_one_platform_operator';

    public function up(): void
    {
        $this->lockTenants();

        // During a fresh replay, the released predecessor migration has just
        // inserted a sole ID-1 bootstrap row and is still the most recently
        // recorded migration. It is not retained application data yet. Remove
        // that artifact so normal sequence-backed provisioning creates the
        // operator without a forced numeric identity. Upgrade databases have
        // later recorded migrations, so ID 1 remains deterministic legacy
        // input that must be preserved. This provenance check deliberately
        // avoids making Base/Tenancy inspect a Core/Company table.
        if ($this->isFreshBootstrapReplay()
            && DB::table('tenants')->count() === 1
            && DB::table('tenants')->where('id', 1)->exists()) {
            DB::table('tenants')->where('id', 1)->delete();
        }

        // ID 1 is migration input only: it identifies the operator under the
        // schema being upgraded. Runtime code resolves the explicit marker.
        $legacyOperator = DB::table('tenants')->where('id', 1)->first();

        if ($legacyOperator === null && DB::table('tenants')->exists()) {
            throw new RuntimeException(
                'Cannot identify the platform-operator tenant: legacy tenant id 1 does not exist. Restore it or designate the operator before retrying the migration.'
            );
        }

        if ($legacyOperator?->deleted_at !== null) {
            throw new RuntimeException(
                'Cannot designate legacy tenant id 1 as the platform operator because it is soft-deleted. Restore the tenant before retrying the migration.'
            );
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->boolean('is_platform_operator')->default(false);
        });

        if ($legacyOperator !== null) {
            DB::table('tenants')->where('id', 1)->update([
                'is_platform_operator' => true,
                'updated_at' => now(),
            ]);
        }

        DB::statement(
            'CREATE UNIQUE INDEX tenants_one_platform_operator ON tenants (is_platform_operator) WHERE is_platform_operator = TRUE'
        );
    }

    public function down(): void
    {
        $operators = DB::table('tenants')
            ->where('is_platform_operator', true)
            ->get(['id', 'deleted_at']);

        if ($operators->count() > 1) {
            throw new RuntimeException(
                'Cannot roll back explicit platform-operator identity while multiple tenants are marked as the operator.'
            );
        }

        $operator = $operators->first();

        if ($operator?->deleted_at !== null) {
            throw new RuntimeException(
                'Cannot roll back explicit platform-operator identity while the marked operator tenant is soft-deleted.'
            );
        }

        if ($operator !== null && (int) $operator->id !== 1) {
            throw new RuntimeException(
                'Cannot roll back explicit platform-operator identity after a non-legacy operator tenant has been used.'
            );
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex(self::OPERATOR_INDEX);
            $table->dropColumn('is_platform_operator');
        });
    }

    private function isFreshBootstrapReplay(): bool
    {
        if (! Schema::hasTable('migrations')) {
            return false;
        }

        return DB::table('migrations')
            ->orderByDesc('id')
            ->value('migration') === '0100_01_25_000000_create_tenants_table';
    }

    private function lockTenants(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE tenants IN SHARE ROW EXCLUSIVE MODE');
        }
    }
};
