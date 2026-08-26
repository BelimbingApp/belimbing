<?php

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;
use App\Base\Software\Exceptions\UpstreamSyncUnavailableException;
use App\Base\Software\Livewire\Deployment\Index;
use App\Base\Software\Services\UpstreamSyncGate;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function (): void {
    Process::fake(fn () => Process::result());
});

/**
 * @param  list<string>  $capabilities
 */
function softwareCapabilityUser(array $capabilities): User
{
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    foreach ($capabilities as $capability) {
        PrincipalCapability::query()->create([
            'company_id' => $company->id,
            'principal_type' => PrincipalType::USER->value,
            'principal_id' => $user->id,
            'capability_key' => $capability,
            'is_allowed' => true,
        ]);
    }

    app(TenantContext::class)->set((int) $user->tenant_id);

    return $user;
}

function setDeploymentRole(?string $role): void
{
    $settings = app(SettingsService::class);

    if ($role === null || $role === '') {
        $settings->forget(UpstreamSyncGate::ROLE_SETTING_KEY);

        return;
    }

    $settings->set(UpstreamSyncGate::ROLE_SETTING_KEY, $role);
}

test('upstream sync is unavailable when the deployment role is unset', function (): void {
    setDeploymentRole(null);

    $gate = app(UpstreamSyncGate::class);
    $availability = $gate->roleAvailability();

    expect($availability['allowed'])->toBeFalse()
        ->and($availability['reason_code'])->toBe('unset');

    $admin = createAdminUser();
    expect($gate->availability(Actor::forUser($admin))['allowed'])->toBeFalse();

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee(__('Upstream sync'))
        ->assertSee(__('Upstream synchronization is unavailable until an operator sets the Software deployment role to development or staging.'))
        ->call('prepareUpstreamSync')
        ->assertForbidden();
});

test('upstream sync is unavailable for a production deployment role', function (): void {
    setDeploymentRole('production');

    $gate = app(UpstreamSyncGate::class);
    expect($gate->roleAvailability()['reason_code'])->toBe('production_role');

    Livewire::actingAs(createAdminUser())
        ->test(Index::class)
        ->assertSee(__('Upstream synchronization is unavailable while the Software deployment role is production.'))
        ->call('prepareUpstreamSync')
        ->assertForbidden();
});

test('upstream sync is unavailable on a production APP_ENV even with a development role', function (): void {
    setDeploymentRole('development');
    Config::set('app.env', 'production');

    $gate = app(UpstreamSyncGate::class);
    expect($gate->roleAvailability()['reason_code'])->toBe('production_env');

    Livewire::actingAs(createAdminUser())
        ->test(Index::class)
        ->call('prepareUpstreamSync')
        ->assertForbidden();
});

test('upstream sync is unavailable for an unrecognised deployment role', function (): void {
    // Bypass definition validation to simulate a corrupted/legacy row.
    Setting::query()->updateOrCreate(
        [
            'key' => UpstreamSyncGate::ROLE_SETTING_KEY,
            'scope_type' => null,
            'scope_id' => null,
        ],
        [
            'value' => json_encode('lab'),
        ],
    );

    expect(app(UpstreamSyncGate::class)->roleAvailability()['reason_code'])->toBe('unrecognised');
});

test('development role without upstream-sync capability cannot prepare sync', function (): void {
    setDeploymentRole('development');

    $user = softwareCapabilityUser([
        'admin.system.software.updates.manage',
    ]);

    $availability = app(UpstreamSyncGate::class)->availability(Actor::forUser($user));

    expect($availability['allowed'])->toBeFalse()
        ->and($availability['role_allows'])->toBeTrue()
        ->and($availability['reason_code'])->toBe('capability');

    Livewire::actingAs($user)
        ->test(Index::class)
        ->assertSee(__('You do not have permission to synchronize with the framework upstream.'))
        ->call('prepareUpstreamSync')
        ->assertForbidden();
});

test('development role with upstream-sync capability can pass the gate stub', function (): void {
    setDeploymentRole('development');

    $admin = createAdminUser();
    $gate = app(UpstreamSyncGate::class);

    expect($gate->availability(Actor::forUser($admin))['allowed'])->toBeTrue();
    $gate->assertCanSync(Actor::forUser($admin));

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->assertSee(__('Available for this installation.'))
        ->call('prepareUpstreamSync')
        ->assertDispatched('notify');
});

test('read-only upstream visibility still renders when the sync gate is closed', function (): void {
    setDeploymentRole(null);

    // Minimal platform status with an upstream block, without requiring a full git fake.
    Livewire::actingAs(createAdminUser())
        ->test(Index::class)
        ->assertSee(__('Upstream sync'))
        ->assertDontSee(__('Available for this installation.'));
});

test('assertCanSync throws the domain exception with the operator-facing reason', function (): void {
    setDeploymentRole('staging');
    Config::set('app.env', 'production');

    $gate = app(UpstreamSyncGate::class);

    expect(fn () => $gate->assertCanSync(Actor::forUser(createAdminUser())))
        ->toThrow(UpstreamSyncUnavailableException::class, 'production installation');
});
