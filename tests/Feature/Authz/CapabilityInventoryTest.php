<?php

use App\Base\Authz\Capability\CapabilityInventory;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;

/**
 * The operator capabilities page's data (#753).
 *
 * The page answers "who can do this", so the two numbers that must not lie are
 * the holder count and whether the capability exists at all. A capability
 * declared in a module config but dropped by CapabilityCatalog is denied to
 * everybody at runtime; listing it beside working ones, with holders, would
 * tell an operator the opposite of the truth.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

/** @return array<string, mixed> */
function inventoryFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Inventory Tenant']);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);

    $role = Role::query()->create([
        'company_id' => null, 'code' => 'inventory_probe',
        'name' => 'Inventory probe', 'description' => 'Fixture role for the capabilities page.',
    ]);

    return compact('tenantId', 'company', 'role');
}

function inventoryHolder(array $f, object $company): User
{
    $user = User::factory()->create(['company_id' => $company->id]);
    PrincipalRole::query()->create([
        'company_id' => $company->id, 'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id, 'role_id' => $f['role']->id,
    ]);

    return $user;
}

function inventoryRow(string $capability): ?object
{
    return collect(app(CapabilityInventory::class)->rows())
        ->first(static fn (object $row): bool => $row->capability === $capability);
}

it('counts holders in the ambient tenant and not a peer tenant', function (): void {
    $f = inventoryFixture();
    inventoryHolder($f, $f['company']);
    inventoryHolder($f, $f['company']);

    // A second tenant with its own company and its own holder of the same
    // global role. Delete the tenant scope on the count and this one is added.
    [$peerTenant, $peerCompany] = createTenantWithCompany(['name' => 'Peer Inventory Tenant']);
    inventoryHolder($f, $peerCompany);

    app(TenantContext::class)->set($f['tenantId']);
    $row = inventoryRow('admin.user.view');

    expect($row)->not->toBeNull()
        ->and($row->holders)->toBe(0);

    // The fixture role grants nothing yet, so attach it to a real capability
    // and re-read.
    $f['role']->capabilities()->create(['capability_key' => 'admin.user.view', 'is_allowed' => true]);
    expect(inventoryRow('admin.user.view')->holders)->toBe(2);
});

it('names the module that declared each capability', function (): void {
    inventoryFixture();

    expect(inventoryRow('admin.user.view')->modules)->toContain('Base/Authz')
        ->and(inventoryRow('admin.user.view')->conflicted)->toBeFalse();
});

it('marks a capability the catalog rejected, with the reason, and reports no holder count', function (): void {
    inventoryFixture();

    // people.organisation.audience.hod is declared by the People module and
    // dropped for an unknown verb, so it is denied to everybody at runtime.
    $row = inventoryRow('people.organisation.audience.hod');

    expect($row)->not->toBeNull()
        ->and($row->rejectedReason)->toContain('unknown verb')
        ->and($row->holders)->toBeNull();
});

it('lists the roles that grant a capability', function (): void {
    $f = inventoryFixture();
    $f['role']->capabilities()->create(['capability_key' => 'admin.user.view', 'is_allowed' => true]);

    expect(inventoryRow('admin.user.view')->roles)->toContain('inventory_probe');
});

it('shows a capability declared by two modules as a conflict naming both', function (): void {
    $inventory = app(CapabilityInventory::class);

    // Declared twice by construction rather than by planting a file: the page
    // must render a conflict whatever produced it.
    $rows = $inventory->rowsFrom(
        declarations: [
            'admin.thing.view' => ['Base/Authz', 'Core/User'],
        ],
        rejected: [],
    );

    expect($rows[0]->conflicted)->toBeTrue()
        ->and($rows[0]->modules)->toBe(['Base/Authz', 'Core/User']);
});
