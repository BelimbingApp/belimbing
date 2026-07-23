<?php

use App\Base\Foundation\Services\DomainInstaller;
use App\Base\Foundation\Services\LandingPageResolver;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\User\Livewire\Settings\Profile;
use App\Modules\Core\User\Models\User;
use Livewire\Livewire;

it('lands on the user-preferred menu item when it is still visible', function (): void {
    $user = createAdminUser();
    app(SettingsService::class)->set(
        LandingPageResolver::SETTING_KEY,
        'admin.system.software.modules',
        Scope::user((int) $user->getKey(), $user->getCompanyId()),
    );

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('admin.system.software.modules.index'));
});

it('falls back to the dashboard when the preference is unknown or inaccessible', function (): void {
    $user = User::factory()->create();
    app(SettingsService::class)->set(
        LandingPageResolver::SETTING_KEY,
        'zz.no.such.item',
        Scope::user((int) $user->getKey(), $user->getCompanyId()),
    );

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

it('lands domain-capable admins on the Business Domains screen when no domains are installed', function (): void {
    $installer = Mockery::mock(DomainInstaller::class);
    $installer->shouldReceive('hasAnyInstalled')->andReturnFalse();
    app()->instance(DomainInstaller::class, $installer);

    $this->actingAs(createAdminUser())
        ->get('/')
        ->assertRedirect(route('admin.system.software.modules.index'));
});

it('lands ordinary users on the dashboard even when no domains are installed', function (): void {
    $installer = Mockery::mock(DomainInstaller::class);
    $installer->shouldReceive('hasAnyInstalled')->andReturnFalse();
    app()->instance(DomainInstaller::class, $installer);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

it('saves the landing preference from the profile page', function (): void {
    $user = createAdminUser();

    $this->actingAs($user);

    Livewire::test(Profile::class)
        ->set('landingMenuId', 'admin.system.software.modules')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $scope = Scope::user((int) $user->getKey(), $user->getCompanyId());

    expect(app(SettingsService::class)->get(LandingPageResolver::SETTING_KEY, $scope))
        ->toBe('admin.system.software.modules');

    Livewire::test(Profile::class)
        ->set('landingMenuId', '')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect(app(SettingsService::class)->has(LandingPageResolver::SETTING_KEY, $scope))->toBeFalse();
});

it('rejects a landing preference outside the user’s visible menu', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Profile::class)
        ->set('landingMenuId', 'zz.no.such.item')
        ->call('updateProfileInformation')
        ->assertHasErrors('landingMenuId');
});
