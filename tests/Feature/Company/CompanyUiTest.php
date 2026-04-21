<?php

use App\Modules\Core\Company\Exceptions\LicenseeCompanyDeletionException;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Livewire\Livewire;

// ---------------------------------------------------------------------------
// Licensee company tests
// ---------------------------------------------------------------------------

test('licensee company cannot be deleted from index', function (): void {
    $user = User::factory()->create();
    $licensee = Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);

    $this->actingAs($user);

    Livewire::test('admin.companies.index')
        ->call('delete', $licensee->id);

    expect(Company::query()->find($licensee->id))->not()->toBeNull();
});

test('licensee company model prevents deletion', function (): void {
    $licensee = Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);

    $licensee->delete();
})->throws(LicenseeCompanyDeletionException::class, 'The licensee company (id=1) cannot be deleted.');

test('company isLicensee returns true for id 1 and false for others', function (): void {
    $licensee = Company::query()->find(Company::LICENSEE_ID)
        ?? Company::factory()->create(['id' => Company::LICENSEE_ID]);
    $other = Company::factory()->create();

    expect($licensee->isLicensee())->toBeTrue()
        ->and($other->isLicensee())->toBeFalse();
});

test('company can be created from create page component', function (): void {
    $user = User::factory()->create();
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
