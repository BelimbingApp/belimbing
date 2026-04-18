<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Database\Console\Commands\MigrateCommand;
use App\Base\Database\Models\TableRegistry;
use App\Base\Foundation\Services\FrameworkPrimitivesProvisioner;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

const BLB_MIGRATE_COMMAND_TEST_ADMIN_EMAIL = 'setup-admin@example.com';
const BLB_MIGRATE_COMMAND_TEST_ADMIN_NAME = 'Setup Admin';
const BLB_MIGRATE_COMMAND_TEST_ADMIN_PASSWORD = 'password123';
const BLB_MIGRATE_COMMAND_TEST_COMPANY_NAME = 'Setup Company';
const BLB_MIGRATE_COMMAND_TEST_COMPANY_CODE = 'setup_company_code';

afterEach(function (): void {
    putenv('ADMIN_EMAIL');
    putenv('ADMIN_NAME');
    putenv('ADMIN_PASSWORD');
    putenv('LICENSEE_COMPANY_NAME');
    putenv('LICENSEE_COMPANY_CODE');

    unset($_ENV['ADMIN_EMAIL'], $_ENV['ADMIN_NAME'], $_ENV['ADMIN_PASSWORD']);
    unset($_ENV['LICENSEE_COMPANY_NAME'], $_ENV['LICENSEE_COMPANY_CODE']);
    unset($_SERVER['ADMIN_EMAIL'], $_SERVER['ADMIN_NAME'], $_SERVER['ADMIN_PASSWORD']);
    unset($_SERVER['LICENSEE_COMPANY_NAME'], $_SERVER['LICENSEE_COMPANY_CODE']);
});

test('migrate command provisions licensee with preferred company code from env', function (): void {
    DB::table('companies')->where('id', Company::LICENSEE_ID)->delete();

    putenv('LICENSEE_COMPANY_NAME='.BLB_MIGRATE_COMMAND_TEST_COMPANY_NAME);
    putenv('LICENSEE_COMPANY_CODE='.BLB_MIGRATE_COMMAND_TEST_COMPANY_CODE);

    $_ENV['LICENSEE_COMPANY_NAME'] = BLB_MIGRATE_COMMAND_TEST_COMPANY_NAME;
    $_ENV['LICENSEE_COMPANY_CODE'] = BLB_MIGRATE_COMMAND_TEST_COMPANY_CODE;
    $_SERVER['LICENSEE_COMPANY_NAME'] = BLB_MIGRATE_COMMAND_TEST_COMPANY_NAME;
    $_SERVER['LICENSEE_COMPANY_CODE'] = BLB_MIGRATE_COMMAND_TEST_COMPANY_CODE;

    $provisioner = new FrameworkPrimitivesProvisioner;
    $provisioner->provisionLicensee(BLB_MIGRATE_COMMAND_TEST_COMPANY_NAME, BLB_MIGRATE_COMMAND_TEST_COMPANY_CODE);

    $company = Company::query()->findOrFail(Company::LICENSEE_ID);

    expect($company->name)
        ->toBe(BLB_MIGRATE_COMMAND_TEST_COMPANY_NAME)
        ->and($company->code)
        ->toBe(BLB_MIGRATE_COMMAND_TEST_COMPANY_CODE);
});

test('migrate command upserts the existing licensee row onto company id 1', function (): void {
    Company::query()->whereKey(Company::LICENSEE_ID)->update([
        'name' => 'Old Licensee',
        'code' => 'old_licensee',
        'status' => 'archived',
    ]);

    putenv('LICENSEE_COMPANY_NAME='.BLB_MIGRATE_COMMAND_TEST_COMPANY_NAME);
    putenv('LICENSEE_COMPANY_CODE='.BLB_MIGRATE_COMMAND_TEST_COMPANY_CODE);

    $_ENV['LICENSEE_COMPANY_NAME'] = BLB_MIGRATE_COMMAND_TEST_COMPANY_NAME;
    $_ENV['LICENSEE_COMPANY_CODE'] = BLB_MIGRATE_COMMAND_TEST_COMPANY_CODE;
    $_SERVER['LICENSEE_COMPANY_NAME'] = BLB_MIGRATE_COMMAND_TEST_COMPANY_NAME;
    $_SERVER['LICENSEE_COMPANY_CODE'] = BLB_MIGRATE_COMMAND_TEST_COMPANY_CODE;

    $provisioner = new FrameworkPrimitivesProvisioner;
    $provisioner->provisionLicensee(BLB_MIGRATE_COMMAND_TEST_COMPANY_NAME, BLB_MIGRATE_COMMAND_TEST_COMPANY_CODE);

    $company = Company::query()->findOrFail(Company::LICENSEE_ID);

    expect($company->name)
        ->toBe(BLB_MIGRATE_COMMAND_TEST_COMPANY_NAME)
        ->and($company->code)
        ->toBe(BLB_MIGRATE_COMMAND_TEST_COMPANY_CODE)
        ->and($company->status)
        ->toBe('active');
});

test('framework primitives provisioner assigns core admin role when creating the fresh install admin user', function (): void {
    setupAuthzRoles();
    Company::provisionLicensee();

    // Use provisioner directly instead of the helper function
    $provisioner = new FrameworkPrimitivesProvisioner;
    $user = $provisioner->provisionAdminUser();

    $role = Role::query()
        ->whereNull('company_id')
        ->where('code', 'core_admin')
        ->firstOrFail();

    expect(PrincipalRole::query()->where([
        'company_id' => Company::LICENSEE_ID,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ])->exists())->toBeTrue();
});

test('framework primitives provisioner backfills core admin role for an existing admin user', function (): void {
    setupAuthzRoles();
    Company::provisionLicensee();

    $user = User::factory()->create([
        'company_id' => Company::LICENSEE_ID,
        'email' => BLB_MIGRATE_COMMAND_TEST_ADMIN_EMAIL,
        'name' => BLB_MIGRATE_COMMAND_TEST_ADMIN_NAME,
    ]);

    // Assign as admin user so provisioner finds it
    Company::query()->find(Company::LICENSEE_ID)->assignAdminUser($user);

    $provisioner = new FrameworkPrimitivesProvisioner;
    $provisioner->provisionAdminUser();

    $role = Role::query()
        ->whereNull('company_id')
        ->where('code', 'core_admin')
        ->firstOrFail();

    expect(PrincipalRole::query()->where([
        'company_id' => Company::LICENSEE_ID,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ])->exists())->toBeTrue();
});

test('framework primitives provisioner prefers bootstrap payload over existing licensee user', function (): void {
    setupAuthzRoles();
    Company::provisionLicensee();

    // Clear any canonical admin anchor and existing licensee users to simulate
    // a partially seeded database with no admin assignment.
    $licensee = Company::query()->find(Company::LICENSEE_ID);
    $licensee->metadata = [];
    $licensee->save();
    DB::table('users')->where('company_id', Company::LICENSEE_ID)->delete();

    // Create a pre-existing licensee user that has no canonical admin anchor.
    // This simulates a stale user from a prior aborted setup.
    $staleUser = User::factory()->create([
        'company_id' => Company::LICENSEE_ID,
        'email' => 'stale-user@example.com',
        'name' => 'Stale User',
    ]);

    // Provide a bootstrap file with the operator's intended admin identity.
    $bootstrapFile = tempnam(sys_get_temp_dir(), 'blb-admin-test-');
    file_put_contents($bootstrapFile, implode("\n", [
        BLB_MIGRATE_COMMAND_TEST_ADMIN_NAME,
        BLB_MIGRATE_COMMAND_TEST_ADMIN_EMAIL,
        BLB_MIGRATE_COMMAND_TEST_ADMIN_PASSWORD,
    ]));

    try {
        $provisioner = new FrameworkPrimitivesProvisioner(null, $bootstrapFile);
        $admin = $provisioner->provisionAdminUser();

        // The bootstrap identity must win — not the stale user.
        expect($admin->email)->toBe(BLB_MIGRATE_COMMAND_TEST_ADMIN_EMAIL)
            ->and($admin->name)->toBe(BLB_MIGRATE_COMMAND_TEST_ADMIN_NAME)
            ->and($admin->id)->not->toBe($staleUser->id);

        // Canonical anchor must point to the bootstrap user, not the stale one.
        $licensee = Company::query()->find(Company::LICENSEE_ID);
        expect($licensee->adminUserId())->toBe($admin->id);
    } finally {
        @unlink($bootstrapFile);
    }
});

test('migrate command reports orphaned registry entries removed during reconciliation', function (): void {
    TableRegistry::query()->create([
        'table_name' => 'ghost_registry_entry',
        'module_name' => 'User',
        'module_path' => 'app/Modules/Core/User',
        'migration_file' => '0200_01_20_000001_create_ghost_registry_entry.php',
        'is_stable' => true,
        'stabilized_at' => now(),
    ]);

    $command = app(MigrateCommand::class);
    $command->setLaravel(app());

    $output = new BufferedOutput;
    $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

    $method = new ReflectionMethod($command, 'reportRemovedRegistryEntries');
    $method->invoke($command, ['ghost_registry_entry']);

    expect($output->fetch())
        ->toContain('Removed 1 orphaned table registry entry that no longer matches any declared or live relation.')
        ->toContain('ghost_registry_entry');
});
