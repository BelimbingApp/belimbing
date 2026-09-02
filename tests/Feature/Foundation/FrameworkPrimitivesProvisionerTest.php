<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Foundation\Exceptions\FrameworkPrimitivesNotConfiguredException;
use App\Base\Tenancy\Models\Tenant;
use App\Core\Company\Models\TenantPrimaryCompany;
use App\Core\Company\Services\FrameworkPrimitivesProvisioner;
use App\Core\Company\Services\PrimaryCompanyManager;
use App\Core\Employee\Exceptions\SystemAgentInvariantViolationException;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const BLB_OPERATOR_PROVISIONER_ADMIN_EMAIL = 'provisioner-admin@example.com';
const BLB_OPERATOR_PROVISIONER_ADMIN_NAME = 'Provisioner Admin';
const BLB_OPERATOR_PROVISIONER_ADMIN_PASSWORD = 'secure_password_123';

/**
 * Mark the baseline operator as an ordinary tenant so provisioning must use
 * database sequences rather than semantic IDs.
 */
function makePlatformOperatorUnprovisioned(): Tenant
{
    $formerOperator = platformOperatorTenant();
    DB::table('tenants')->where('id', $formerOperator->id)->update(['is_platform_operator' => false]);

    expect(Tenant::platformOperator())->toBeNull();

    return $formerOperator;
}

/** @return array{FrameworkPrimitivesProvisioner, string} */
function operatorProvisionerWithBootstrap(): array
{
    $bootstrap = tempnam(sys_get_temp_dir(), 'blb-admin-test-');
    file_put_contents($bootstrap, implode("\n", [
        BLB_OPERATOR_PROVISIONER_ADMIN_NAME,
        BLB_OPERATOR_PROVISIONER_ADMIN_EMAIL,
        BLB_OPERATOR_PROVISIONER_ADMIN_PASSWORD,
    ]));

    return [
        new FrameworkPrimitivesProvisioner(app(PrimaryCompanyManager::class), bootstrapAdminFile: $bootstrap),
        $bootstrap,
    ];
}

test('platform operator and its primary company do not depend on id 1', function (): void {
    makePlatformOperatorUnprovisioned();
    $provisioner = app(FrameworkPrimitivesProvisioner::class);

    expect($provisioner->provisionPlatformOperatorTenant('Sequence-safe Operator'))->toBeTrue();
    $company = $provisioner->provisionPlatformOperatorCompany('Sequence-safe Operator', 'operator_code');
    $tenant = Tenant::requirePlatformOperator();

    expect($tenant->id)->not->toBe(1)
        ->and($company->id)->not->toBe(1)
        ->and($company->tenant_id)->toBe($tenant->id)
        ->and(app(PrimaryCompanyManager::class)->platformOperatorCompany()->is($company))->toBeTrue();
});

test('database permits only one marked platform-operator tenant', function (): void {
    DB::table('tenants')->insert([
        'name' => 'Second Operator',
        'status' => 'active',
        'is_platform_operator' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

test('fresh provisioning creates the operator relationship and initial administrator transactionally', function (): void {
    setupAuthzRoles();
    makePlatformOperatorUnprovisioned();
    DB::table('employees')->where('id', Employee::LARA_ID)->delete();
    [$provisioner, $bootstrap] = operatorProvisionerWithBootstrap();

    try {
        $provisioner->provision('Fresh Operator', 'fresh_operator');
    } finally {
        @unlink($bootstrap);
    }

    $tenant = Tenant::requirePlatformOperator();
    $company = app(PrimaryCompanyManager::class)->platformOperatorCompany();
    $user = User::query()->where('email', BLB_OPERATOR_PROVISIONER_ADMIN_EMAIL)->firstOrFail();
    $role = Role::query()->whereNull('company_id')->where('code', 'core_admin')->firstOrFail();

    expect($tenant->id)->not->toBe(1)
        ->and($company->id)->not->toBe(1)
        ->and($company->tenant_id)->toBe($tenant->id)
        ->and(TenantPrimaryCompany::query()->where([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ])->exists())->toBeTrue()
        ->and($user->company_id)->toBe($company->id)
        ->and(Hash::check(BLB_OPERATOR_PROVISIONER_ADMIN_PASSWORD, $user->password))->toBeTrue()
        ->and(PrincipalRole::query()->where([
            'company_id' => $company->id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'role_id' => $role->id,
        ])->exists())->toBeTrue();
});

test('operator provisioning never reassigns a bootstrap administrator from another tenant', function (): void {
    [, $otherCompany] = createTenantWithCompany(['name' => 'Other Admin Tenant']);
    $existingUser = User::factory()->create([
        'company_id' => $otherCompany->id,
        'email' => BLB_OPERATOR_PROVISIONER_ADMIN_EMAIL,
    ]);
    [$provisioner, $bootstrap] = operatorProvisionerWithBootstrap();

    try {
        expect(fn () => $provisioner->provisionAdminUser())
            ->toThrow(FrameworkPrimitivesNotConfiguredException::class, 'already belongs to another company');
    } finally {
        @unlink($bootstrap);
    }

    expect($existingUser->refresh()->company_id)->toBe($otherCompany->id);
});

test('operator provisioning is idempotent and updates the same explicit records', function (): void {
    $provisioner = app(FrameworkPrimitivesProvisioner::class);
    $originalTenant = platformOperatorTenant();
    $originalCompany = platformOperatorCompany();

    expect($provisioner->provisionPlatformOperatorTenant('Renamed Operator'))->toBeFalse();
    $updatedCompany = $provisioner->provisionPlatformOperatorCompany('Renamed Operator', 'Renamed Code');

    expect(Tenant::requirePlatformOperator()->id)->toBe($originalTenant->id)
        ->and(Tenant::requirePlatformOperator()->name)->toBe('Renamed Operator')
        ->and($updatedCompany->id)->toBe($originalCompany->id)
        ->and($updatedCompany->code)->toBe('renamed_code')
        ->and(TenantPrimaryCompany::query()->where('tenant_id', $originalTenant->id)->count())->toBe(1);
});

test('system Agent provisioning never rehomes an employee from another tenant', function (): void {
    $operatorCompany = platformOperatorCompany();
    [, $otherCompany] = createTenantWithCompany(['name' => 'Other Agent Tenant']);

    DB::table('employees')
        ->where('id', Employee::LARA_ID)
        ->update(['company_id' => $otherCompany->id]);

    expect(fn () => Employee::provisionLara())
        ->toThrow(SystemAgentInvariantViolationException::class);

    expect(Employee::query()->findOrFail(Employee::LARA_ID)->company_id)
        ->toBe($otherCompany->id)
        ->not->toBe($operatorCompany->id);
});

test('system Agent provisioning leaves the auto-numbered employee sequence clear of its explicit id', function (): void {
    // Lara is inserted at an explicit primary key. PostgreSQL does not advance
    // a serial sequence for an explicit-ID insert, so without
    // Employee::provisionLara()'s sequence reset the very next auto-numbered
    // employee is handed Lara's id and the insert dies on the primary key.
    DB::table('employees')->where('id', Employee::LARA_ID)->delete();

    // Reproduce a fresh install. A PostgreSQL serial sequence starts before
    // its first value and an explicit-ID insert leaves it there, which is the
    // state provisionLara() has to correct; SQLite derives the next id from
    // max(rowid) and arrives in that state by itself, so there is nothing to
    // arrange for it. The setup differs because the drivers' id allocators
    // differ — the assertion below is the same on both.
    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement("SELECT setval(pg_get_serial_sequence('employees', 'id'), 1, false)");
    }

    expect(Employee::provisionLara())->toBeTrue();

    $next = Employee::factory()->create([
        'company_id' => platformOperatorCompany()->id,
        'status' => 'active',
    ]);

    expect((int) $next->id)->toBeGreaterThan(Employee::LARA_ID)
        ->and(Employee::query()->whereKey(Employee::LARA_ID)->value('employee_number'))->toBe('SYS-001');
});

test('provisioner emits platform-operator terminology', function (): void {
    makePlatformOperatorUnprovisioned();
    $messages = [];
    $provisioner = new FrameworkPrimitivesProvisioner(
        app(PrimaryCompanyManager::class),
        static function (string $message) use (&$messages): void {
            $messages[] = $message;
        },
    );

    $provisioner->provisionPlatformOperatorTenant('New Operator');
    $provisioner->provisionPlatformOperatorCompany('New Operator');

    expect($messages)->toContain('Created platform-operator tenant: New Operator')
        ->and($messages)->toContain('Created platform-operator primary company: New Operator');
});
