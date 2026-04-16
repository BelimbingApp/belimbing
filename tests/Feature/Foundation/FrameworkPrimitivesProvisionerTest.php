<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Foundation\Services\FrameworkPrimitivesProvisioner;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\DB;

const BLB_FRAMEWORK_PROVISIONER_TEST_ADMIN_EMAIL = 'provisioner-admin@example.com';
const BLB_FRAMEWORK_PROVISIONER_TEST_ADMIN_NAME = 'Provisioner Admin';
const BLB_FRAMEWORK_PROVISIONER_TEST_ADMIN_PASSWORD = 'secure_password_123';
const BLB_FRAMEWORK_PROVISIONER_TEST_COMPANY_NAME = 'Provisioner Test Company';
const BLB_FRAMEWORK_PROVISIONER_TEST_COMPANY_CODE = 'provisioner_test_code';

afterEach(function (): void {
    putenv('LICENSEE_COMPANY_NAME');
    putenv('LICENSEE_COMPANY_CODE');

    unset($_ENV['LICENSEE_COMPANY_NAME'], $_ENV['LICENSEE_COMPANY_CODE']);
    unset($_SERVER['LICENSEE_COMPANY_NAME'], $_SERVER['LICENSEE_COMPANY_CODE']);
});

test('provisioner creates licensee company when it does not exist', function (): void {
    DB::table('companies')->where('id', Company::LICENSEE_ID)->delete();

    $provisioner = new FrameworkPrimitivesProvisioner;
    $wasCreated = $provisioner->provisionLicensee(BLB_FRAMEWORK_PROVISIONER_TEST_COMPANY_NAME, BLB_FRAMEWORK_PROVISIONER_TEST_COMPANY_CODE);

    expect($wasCreated)->toBeTrue();

    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    expect($company->name)->toBe(BLB_FRAMEWORK_PROVISIONER_TEST_COMPANY_NAME);
    expect($company->code)->toBe('provisioner_test_code'); // normalized
    expect($company->status)->toBe('active');
});

test('provisioner updates existing licensee company and returns false', function (): void {
    Company::provisionLicensee('Original Name', 'original_code');

    $provisioner = new FrameworkPrimitivesProvisioner;
    $wasCreated = $provisioner->provisionLicensee('Updated Name', 'updated_code');

    expect($wasCreated)->toBeFalse();

    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    expect($company->name)->toBe('Updated Name');
    expect($company->code)->toBe('updated_code');
    expect($company->status)->toBe('active');
});

test('provisioner calls output callback with messages', function (): void {
    DB::table('companies')->where('id', Company::LICENSEE_ID)->delete();

    $messages = [];
    $provisioner = new FrameworkPrimitivesProvisioner(function (string $msg) use (&$messages): void {
        $messages[] = $msg;
    });

    $provisioner->provisionLicensee('New Company');

    expect($messages)->toContain('Created licensee company: New Company');
});

test('provisioner returns null for admin user when licensee does not exist', function (): void {
    DB::table('companies')->where('id', Company::LICENSEE_ID)->delete();

    $provisioner = new FrameworkPrimitivesProvisioner;
    $user = $provisioner->provisionAdminUser();

    expect($user)->toBeNull();
});

test('provisioner creates admin user with default values', function (): void {
    Company::provisionLicensee();

    $provisioner = new FrameworkPrimitivesProvisioner;
    $user = $provisioner->provisionAdminUser();

    expect($user)->toBeInstanceOf(User::class);
    expect($user->email)->toBe('admin@example.com');
    expect($user->name)->toBe('Administrator');
    expect($user->company_id)->toBe(Company::LICENSEE_ID);
});

test('provisioner creates admin user from bootstrap file even when stale licensee users exist', function (): void {
    Company::provisionLicensee();

    // Clear canonical admin anchor and existing users, then re-add a stale user.
    // This simulates a partially seeded DB where a previous setup was aborted.
    $licensee = Company::query()->find(Company::LICENSEE_ID);
    $licensee->metadata = [];
    $licensee->save();
    DB::table('users')->where('company_id', Company::LICENSEE_ID)->delete();

    $staleUser = User::factory()->create([
        'company_id' => Company::LICENSEE_ID,
        'email' => 'stale-provisioner@example.com',
    ]);

    $tempFile = tempnam(sys_get_temp_dir(), 'blb-admin-test-');
    file_put_contents($tempFile, BLB_FRAMEWORK_PROVISIONER_TEST_ADMIN_NAME."\n".BLB_FRAMEWORK_PROVISIONER_TEST_ADMIN_EMAIL."\n".BLB_FRAMEWORK_PROVISIONER_TEST_ADMIN_PASSWORD);

    try {
        $provisioner = new FrameworkPrimitivesProvisioner(null, $tempFile);
        $user = $provisioner->provisionAdminUser();

        expect($user->email)->toBe(BLB_FRAMEWORK_PROVISIONER_TEST_ADMIN_EMAIL)
            ->and($user->name)->toBe(BLB_FRAMEWORK_PROVISIONER_TEST_ADMIN_NAME)
            ->and($user->id)->not->toBe($staleUser->id);
    } finally {
        @unlink($tempFile);
    }
});

test('provisioner reuses existing admin user assigned to licensee', function (): void {
    Company::provisionLicensee();
    $existingUser = User::factory()->create([
        'company_id' => Company::LICENSEE_ID,
        'email' => 'existing@example.com',
    ]);
    Company::query()->find(Company::LICENSEE_ID)->assignAdminUser($existingUser);

    $provisioner = new FrameworkPrimitivesProvisioner;
    $user = $provisioner->provisionAdminUser();

    expect($user->id)->toBe($existingUser->id);
    expect($user->email)->toBe('existing@example.com');
});

test('provisioner assigns core_admin role to admin user', function (): void {
    setupAuthzRoles();
    Company::provisionLicensee();

    $provisioner = new FrameworkPrimitivesProvisioner;
    $user = $provisioner->provisionAdminUser();

    $role = Role::query()
        ->whereNull('company_id')
        ->where('code', 'core_admin')
        ->first();

    expect(PrincipalRole::query()->where([
        'company_id' => Company::LICENSEE_ID,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ])->exists())->toBeTrue();
});

test('provisioner creates Lara when not exists', function (): void {
    Company::provisionLicensee();
    // Delete Lara if exists from other tests
    DB::table('employees')->where('id', 1)->delete();

    $provisioner = new FrameworkPrimitivesProvisioner;
    $wasCreated = $provisioner->provisionLara();

    expect($wasCreated)->toBeTrue();
});

test('provisioner returns false when Lara already exists', function (): void {
    Company::provisionLicensee();
    Employee::provisionLara();

    $provisioner = new FrameworkPrimitivesProvisioner;
    $wasCreated = $provisioner->provisionLara();

    expect($wasCreated)->toBeFalse();
});

test('provisioner calls output callback when creating Lara', function (): void {
    Company::provisionLicensee();
    // Delete Lara if exists from other tests
    DB::table('employees')->where('id', 1)->delete();

    $messages = [];
    $provisioner = new FrameworkPrimitivesProvisioner(function (string $msg) use (&$messages): void {
        $messages[] = $msg;
    });

    $provisioner->provisionLara();

    expect($messages)->toContain('Created Lara (system Agent — orchestrator)');
});

test('provisioner provisions all primitives in correct order', function (): void {
    setupAuthzRoles();
    // Clear all state first
    DB::table('users')->where('company_id', Company::LICENSEE_ID)->delete();
    DB::table('employees')->where('id', 1)->delete();
    DB::table('companies')->where('id', Company::LICENSEE_ID)->delete();

    $messages = [];
    $provisioner = new FrameworkPrimitivesProvisioner(function (string $msg) use (&$messages): void {
        $messages[] = $msg;
    });

    $provisioner->provision(BLB_FRAMEWORK_PROVISIONER_TEST_COMPANY_NAME, BLB_FRAMEWORK_PROVISIONER_TEST_COMPANY_CODE);

    // Verify company was created
    $company = Company::query()->findOrFail(Company::LICENSEE_ID);
    expect($company->name)->toBe(BLB_FRAMEWORK_PROVISIONER_TEST_COMPANY_NAME);

    // Verify admin user was created
    $user = User::query()
        ->where('company_id', Company::LICENSEE_ID)
        ->first();
    expect($user)->not->toBeNull();

    // Verify Lara was created
    $lara = Employee::query()->find(1);
    expect($lara)->not->toBeNull();

    // Verify output messages
    expect($messages)->toContain('Created licensee company: '.BLB_FRAMEWORK_PROVISIONER_TEST_COMPANY_NAME);
    expect($messages)->toContain('Created admin user: admin@example.com');
    expect($messages)->toContain('Created Lara (system Agent — orchestrator)');
});
