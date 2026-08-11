<?php

use App\Base\Menu\Services\MenuConditionRegistry;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Tenancy\Livewire\Admin\Tenants;
use App\Base\Tenancy\Models\Tenant;
use App\Core\User\Models\User;
use Livewire\Livewire;

it('hides the tenancy surface while a single tenant exists', function (): void {
    $user = createAdminUser();

    expect(app(MenuConditionRegistry::class)->allows('tenancy.visible', $user))->toBeFalse();
});

it('reveals the tenancy surface when a second tenant exists', function (): void {
    $user = createAdminUser();
    createTenant(['name' => 'Second Tenant']);

    expect(app(MenuConditionRegistry::class)->allows('tenancy.visible', $user))->toBeTrue();
});

it('reveals the tenancy surface via the explicit platform setting', function (): void {
    $user = createAdminUser();

    app(SettingsService::class)->set('tenancy.show_management', true);

    expect(app(MenuConditionRegistry::class)->allows('tenancy.visible', $user))->toBeTrue();
});

it('lists tenants for admins and blocks users without the capability', function (): void {
    createTenant(['name' => 'Visible Second Tenant']);
    $operatorName = platformOperatorTenant()->name;

    $this->actingAs(createAdminUser())
        ->get(route('admin.tenancy.tenants'))
        ->assertOk()
        ->assertSee('Visible Second Tenant')
        ->assertSee($operatorName);

    $plainUser = User::factory()->create();
    $this->actingAs($plainUser)
        ->get(route('admin.tenancy.tenants'))
        ->assertForbidden();
});

it('creates a tenant with an optional parent through the admin page', function (): void {
    $this->actingAs(createAdminUser());
    [$parent] = createTenantWithCompany(['name' => 'Reseller Tenant']);

    Livewire::test(Tenants::class)
        ->call('$set', 'showCreateModal', true)
        ->set('createName', 'Customer Sub-Tenant')
        ->set('createParentId', $parent->id)
        ->set('createStatus', 'suspended')
        ->call('createTenant')
        ->assertHasNoErrors();

    $tenant = Tenant::query()->where('name', 'Customer Sub-Tenant')->first();

    expect($tenant)->not->toBeNull();
    expect($tenant->parent_id)->toBe($parent->id);
    expect($tenant->status)->toBe('suspended');
});

it('rejects tenant creation without the create capability', function (): void {
    $plainUser = User::factory()->create();
    $this->actingAs($plainUser);

    Livewire::test(Tenants::class)
        ->set('createName', 'Unauthorized Tenant')
        ->call('createTenant')
        ->assertForbidden();

    expect(Tenant::query()->where('name', 'Unauthorized Tenant')->exists())->toBeFalse();
});
