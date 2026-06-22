<?php

use App\Base\Foundation\Services\DomainInstaller;
use App\Base\Foundation\Services\LandingPageResolver;
use App\Modules\Core\User\Livewire\Settings\Profile;
use App\Modules\Core\User\Models\User;
use Livewire\Livewire;

it('lands on the user-preferred menu item when it is still visible', function (): void {
    $user = createAdminUser();
    $user->update(['prefs' => [LandingPageResolver::PREF_KEY => 'admin.system.software.modules']]);

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(route('admin.system.software.modules.index'));
});

it('falls back to the dashboard when the preference is unknown or inaccessible', function (): void {
    $user = User::factory()->create([
        'prefs' => [LandingPageResolver::PREF_KEY => 'zz.no.such.item'],
    ]);

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

    expect($user->refresh()->prefs[LandingPageResolver::PREF_KEY])->toBe('admin.system.software.modules');

    Livewire::test(Profile::class)
        ->set('landingMenuId', '')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->refresh()->prefs[LandingPageResolver::PREF_KEY] ?? null)->toBeNull();
});

it('rejects a landing preference outside the user’s visible menu', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Profile::class)
        ->set('landingMenuId', 'zz.no.such.item')
        ->call('updateProfileInformation')
        ->assertHasErrors('landingMenuId');
});
