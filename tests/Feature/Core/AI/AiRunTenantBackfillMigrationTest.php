<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->originalDefaultConnection = DB::getDefaultConnection();
    config()->set('database.connections.ai_run_tenant_migration', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('ai_run_tenant_migration');
    DB::setDefaultConnection('ai_run_tenant_migration');

    Schema::create('tenants', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('companies', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->nullable();
    });
    Schema::create('employees', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('company_id')->nullable();
    });
    Schema::create('ai_runs', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->unsignedBigInteger('employee_id');
    });
});

afterEach(function (): void {
    DB::purge('ai_run_tenant_migration');
    DB::setDefaultConnection($this->originalDefaultConnection);
});

function aiRunTenantBackfillMigration(): object
{
    return require app_path('Core/AI/Database/Migrations/0200_02_01_000019_add_tenant_id_to_ai_runs_table.php');
}

test('AI run tenant migration backfills legacy rows through employee company ownership', function (): void {
    DB::table('tenants')->insert(['id' => 7]);
    DB::table('companies')->insert(['id' => 11, 'tenant_id' => 7]);
    DB::table('employees')->insert(['id' => 13, 'company_id' => 11]);
    DB::table('ai_runs')->insert(['id' => 'legacy-run', 'employee_id' => 13]);

    aiRunTenantBackfillMigration()->up();

    expect(Schema::hasColumn('ai_runs', 'tenant_id'))->toBeTrue()
        ->and(DB::table('ai_runs')->where('id', 'legacy-run')->value('tenant_id'))->toBe(7);

    expect(fn () => DB::table('ai_runs')->insert([
        'id' => 'missing-tenant',
        'employee_id' => 13,
        'tenant_id' => null,
    ]))->toThrow(QueryException::class);
});

test('AI run tenant migration fails closed before altering schema for orphaned rows', function (): void {
    DB::table('ai_runs')->insert(['id' => 'orphan-run', 'employee_id' => 999]);

    expect(fn () => aiRunTenantBackfillMigration()->up())
        ->toThrow(RuntimeException::class, 'one or more runs have no employee/company tenant ownership');

    expect(Schema::hasColumn('ai_runs', 'tenant_id'))->toBeFalse();
});
