<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Database\Console\Commands\MigrateCommand;
use App\Base\Database\Models\TableRegistry;
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

function invokeEnsureAdminUser(): void
{
    putenv('ADMIN_EMAIL='.BLB_MIGRATE_COMMAND_TEST_ADMIN_EMAIL);
    putenv('ADMIN_NAME='.BLB_MIGRATE_COMMAND_TEST_ADMIN_NAME);
    putenv('ADMIN_PASSWORD='.BLB_MIGRATE_COMMAND_TEST_ADMIN_PASSWORD);

    $_ENV['ADMIN_EMAIL'] = BLB_MIGRATE_COMMAND_TEST_ADMIN_EMAIL;
    $_ENV['ADMIN_NAME'] = BLB_MIGRATE_COMMAND_TEST_ADMIN_NAME;
    $_ENV['ADMIN_PASSWORD'] = BLB_MIGRATE_COMMAND_TEST_ADMIN_PASSWORD;
    $_SERVER['ADMIN_EMAIL'] = BLB_MIGRATE_COMMAND_TEST_ADMIN_EMAIL;
    $_SERVER['ADMIN_NAME'] = BLB_MIGRATE_COMMAND_TEST_ADMIN_NAME;
    $_SERVER['ADMIN_PASSWORD'] = BLB_MIGRATE_COMMAND_TEST_ADMIN_PASSWORD;

    $command = app(MigrateCommand::class);
    $command->setLaravel(app());
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    $method = new ReflectionMethod($command, 'ensureAdminUser');
    $method->invoke($command);
}

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

    $command = app(MigrateCommand::class);
    $command->setLaravel(app());
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    $method = new ReflectionMethod($command, 'ensureFrameworkPrimitives');
    $method->invoke($command);

    $company = Company::query()->findOrFail(Company::LICENSEE_ID);

    expect($company->name)
        ->toBe(BLB_MIGRATE_COMMAND_TEST_COMPANY_NAME)
        ->and($company->code)
        ->toBe(BLB_MIGRATE_COMMAND_TEST_COMPANY_CODE);
});

test('migrate command assigns core admin role when creating the fresh install admin user', function (): void {
    setupAuthzRoles();
    Company::provisionLicensee();

    invokeEnsureAdminUser();

    $user = User::query()
        ->where('email', BLB_MIGRATE_COMMAND_TEST_ADMIN_EMAIL)
        ->firstOrFail();

    $role = Role::query()
        ->whereNull('company_id')
        ->where('code', 'core_admin')
        ->firstOrFail();

    expect(PrincipalRole::query()->where([
        'company_id' => Company::LICENSEE_ID,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ])->exists())->toBeTrue();
});

test('migrate command backfills core admin role for an existing fresh install admin user', function (): void {
    setupAuthzRoles();
    Company::provisionLicensee();

    $user = User::factory()->create([
        'company_id' => Company::LICENSEE_ID,
        'email' => BLB_MIGRATE_COMMAND_TEST_ADMIN_EMAIL,
        'name' => BLB_MIGRATE_COMMAND_TEST_ADMIN_NAME,
    ]);

    invokeEnsureAdminUser();

    $role = Role::query()
        ->whereNull('company_id')
        ->where('code', 'core_admin')
        ->firstOrFail();

    expect(PrincipalRole::query()->where([
        'company_id' => Company::LICENSEE_ID,
        'principal_type' => PrincipalType::HUMAN_USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ])->exists())->toBeTrue();
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
