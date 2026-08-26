<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Exceptions\PrimaryCompanyDeletionException;
use App\Core\Company\Models\Company;
use App\Core\Company\Services\PrimaryCompanyManager;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

// ---------------------------------------------------------------------------
// Primary company tests
// ---------------------------------------------------------------------------

test('primary company cannot be deleted from index', function (): void {
    $user = User::factory()->create();
    $primaryCompany = platformOperatorCompany();
    app(TenantContext::class)->set((int) $user->tenant_id);

    $this->actingAs($user);

    Livewire::test('admin.companies.index')
        ->call('delete', $primaryCompany->id);

    expect(Company::query()->find($primaryCompany->id))->not()->toBeNull();
});

test('primary company model prevents deletion until its role is transferred', function (): void {
    platformOperatorCompany()->delete();
})->throws(PrimaryCompanyDeletionException::class);

test('primary-company identity follows the explicit relationship', function (): void {
    $primaryCompany = platformOperatorCompany();
    $other = Company::factory()->create();

    expect(app(PrimaryCompanyManager::class)->isPrimary($primaryCompany))->toBeTrue()
        ->and(app(PrimaryCompanyManager::class)->isPrimary($other))->toBeFalse();
});

test('company route binding fails closed for a company in another tenant', function (): void {
    $user = createAdminUser();
    [, $foreignCompany] = createTenantWithCompany(['name' => 'Foreign Tenant']);

    $this->withoutVite();

    $this->actingAs($user)
        ->get(route('admin.companies.show', $foreignCompany))
        ->assertNotFound();
});

test('company route binding returns not found without a tenant context', function (): void {
    [, $company] = createTenantWithCompany(['name' => 'Tenantless Binding Target']);
    $user = User::factory()->create(['company_id' => null]);

    Route::get('/test/company-binding-without-tenant/{company}', fn (Company $company) => response()->json([
        'company_id' => $company->id,
    ]))->middleware(['web', 'auth']);

    $response = $this->withoutVite()->actingAs($user)
        ->get('/test/company-binding-without-tenant/'.$company->id);

    $response->assertNotFound();
    expect($response->getContent())->not->toContain('company_id');
});

test('platform-operator setup is unavailable from a customer tenant', function (): void {
    [, $customerCompany] = createTenantWithCompany(['name' => 'Customer Setup Tenant']);
    $customerOwner = createTenantOwnerUser($customerCompany->id);

    $this->withoutVite();

    $this->actingAs($customerOwner)
        ->get(route('admin.setup.platform-operator'))
        ->assertNotFound();
});

test('company can be created from create page component', function (): void {
    $user = createAdminUser();
    $this->actingAs($user);

    Livewire::test('admin.companies.create')
        ->set('name', 'Northwind Holdings')
        ->set('status', 'active')
        ->set('email', 'ops@northwind.example')
        ->set('scopeActivitiesJson', '{"industry":"Logistics"}')
        ->set('metadataJson', '{"employee_count":250}')
        ->call('store')
        ->assertRedirect(route('admin.companies.index'));

    $company = Company::query()->where('name', 'Northwind Holdings')->first();

    expect($company)
        ->not()->toBeNull()
        ->and($company->code)
        ->toBe('northwind_holdings')
        ->and($company->status)
        ->toBe('active')
        ->and($company->email)
        ->toBe('ops@northwind.example')
        ->and($company->scope_activities['industry'])
        ->toBe('Logistics')
        ->and($company->metadata['employee_count'])
        ->toBe(250);
});
