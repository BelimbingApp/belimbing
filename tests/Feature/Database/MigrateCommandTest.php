<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Database\Console\Commands\MigrateCommand;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

const BLB_MIGRATE_COMMAND_TEST_ADMIN_EMAIL = 'setup-admin@example.com';
const BLB_MIGRATE_COMMAND_TEST_ADMIN_NAME = 'Setup Admin';
const BLB_MIGRATE_COMMAND_TEST_ADMIN_PASSWORD = 'password123';

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

    unset($_ENV['ADMIN_EMAIL'], $_ENV['ADMIN_NAME'], $_ENV['ADMIN_PASSWORD']);
    unset($_SERVER['ADMIN_EMAIL'], $_SERVER['ADMIN_NAME'], $_SERVER['ADMIN_PASSWORD']);
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
