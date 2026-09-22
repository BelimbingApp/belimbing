<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $assignments = DB::table('ai_runs')
            ->join('employees', 'employees.id', '=', 'ai_runs.employee_id')
            ->join('companies', 'companies.id', '=', 'employees.company_id')
            ->orderBy('ai_runs.id')
            ->pluck('companies.tenant_id', 'ai_runs.id');

        if ($assignments->count() !== DB::table('ai_runs')->count() || $assignments->contains(null)) {
            throw new RuntimeException(
                'Cannot backfill AI run tenancy because one or more runs have no employee/company tenant ownership.',
            );
        }

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->index()
                ->constrained('tenants')
                ->restrictOnDelete();
        });

        foreach ($assignments as $runId => $tenantId) {
            DB::table('ai_runs')
                ->where('id', $runId)
                ->update(['tenant_id' => $tenantId]);
        }

        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
