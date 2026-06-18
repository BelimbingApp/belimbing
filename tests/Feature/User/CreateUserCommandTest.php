<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a verified user and assigns the requested system role', function (): void {
    setupAuthzRoles();

    $company = Company::factory()->create();

    $this->artisan('blb:user:create', [
        'email' => 'command-created@example.test',
        '--name' => 'Command Created',
        '--company' => $company->id,
        '--role' => 'core_admin',
    ])
        ->expectsQuestion('Password (min 8 chars)', 'password123')
        ->assertSuccessful();

    $user = User::query()->where('email', 'command-created@example.test')->firstOrFail();
    $role = Role::query()->where('code', 'core_admin')->whereNull('company_id')->firstOrFail();

    expect($user->name)->toBe('Command Created')
        ->and($user->company_id)->toBe($company->id)
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(PrincipalRole::query()
            ->where('company_id', $company->id)
            ->where('principal_type', PrincipalType::USER->value)
            ->where('principal_id', $user->id)
            ->where('role_id', $role->id)
            ->exists())->toBeTrue();
});
