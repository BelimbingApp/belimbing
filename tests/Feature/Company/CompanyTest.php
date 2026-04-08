<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\CompanyRelationship;
use App\Modules\Core\Company\Models\RelationshipType;
use Illuminate\Support\Facades\DB;

function removeSeededLicenseeCompany(): void
{
    DB::table('companies')->where('id', Company::LICENSEE_ID)->delete();
}

test('company code is auto-generated from name', function (): void {
    $company = Company::factory()->create([
        'name' => 'My Great Company',
    ]);

    expect($company->code)->toBe('my_great_company');
});

test('provision licensee uses preferred company code when provided', function (): void {
    removeSeededLicenseeCompany();

    Company::provisionLicensee('My Company', 'preferred_licensee');

    $licensee = Company::query()->findOrFail(Company::LICENSEE_ID);

    expect($licensee->code)->toBe('preferred_licensee');
});

test('provision licensee normalizes preferred company code', function (): void {
    removeSeededLicenseeCompany();

    Company::provisionLicensee('My Company', 'Preferred Code 2026');

    $licensee = Company::query()->findOrFail(Company::LICENSEE_ID);

    expect($licensee->code)->toBe('preferred_code_2026');
});

test('provision licensee updates the existing id 1 company in place', function (): void {
    Company::query()->whereKey(Company::LICENSEE_ID)->update([
        'name' => 'Old Licensee',
        'code' => 'old_licensee',
        'status' => 'archived',
    ]);

    $wasCreated = Company::provisionLicensee('New Licensee', 'new licensee');

    $licensee = Company::query()->findOrFail(Company::LICENSEE_ID);

    expect($wasCreated)->toBeFalse()
        ->and($licensee->name)->toBe('New Licensee')
        ->and($licensee->code)->toBe('new_licensee')
        ->and($licensee->status)->toBe('active');
});

test('provision licensee preserves existing code when no preferred code is provided', function (): void {
    Company::query()->whereKey(Company::LICENSEE_ID)->update([
        'name' => 'Old Licensee',
        'code' => 'stable_licensee',
    ]);

    Company::provisionLicensee('Renamed Licensee');

    $licensee = Company::query()->findOrFail(Company::LICENSEE_ID);

    expect($licensee->name)->toBe('Renamed Licensee')
        ->and($licensee->code)->toBe('stable_licensee');
});

test('company can have parent company', function (): void {
    $parent = Company::factory()->create(['name' => 'Parent Corp']);
    $child = Company::factory()->create([
        'name' => 'Child Corp',
        'parent_id' => $parent->id,
    ]);

    expect($child->parent->id)
        ->toBe($parent->id)
        ->and($child->isRoot())
        ->toBeFalse()
        ->and($parent->isRoot())
        ->toBeTrue();
});

test('company can retrieve all ancestors', function (): void {
    $grandparent = Company::factory()->create(['name' => 'Grandparent']);
    $parent = Company::factory()->create([
        'name' => 'Parent',
        'parent_id' => $grandparent->id,
    ]);
    $child = Company::factory()->create([
        'name' => 'Child',
        'parent_id' => $parent->id,
    ]);

    $ancestors = $child->ancestors();

    expect($ancestors)
        ->toHaveCount(2)
        ->and($ancestors->first()->id)
        ->toBe($parent->id)
        ->and($ancestors->last()->id)
        ->toBe($grandparent->id);
});

test('company can find root of hierarchy', function (): void {
    $root = Company::factory()->create(['name' => 'Root Company']);
    $level1 = Company::factory()->create(['parent_id' => $root->id]);
    $level2 = Company::factory()->create(['parent_id' => $level1->id]);
    $level3 = Company::factory()->create(['parent_id' => $level2->id]);

    expect($level3->getRootCompany()->id)
        ->toBe($root->id)
        ->and($level2->getRootCompany()->id)
        ->toBe($root->id)
        ->and($level1->getRootCompany()->id)
        ->toBe($root->id)
        ->and($root->getRootCompany()->id)
        ->toBe($root->id);
});

test('company status transitions work correctly', function (): void {
    $company = Company::factory()->suspended()->create();
    expect($company->isActive())->toBeFalse();

    $company->activate();
    expect($company->isActive())->toBeTrue();

    $company->suspend();
    expect($company->isSuspended())->toBeTrue();

    $company->archive();
    expect($company->isArchived())->toBeTrue();
});

test('company full address formats correctly', function (): void {
    $company = Company::factory()->create();

    $address = \App\Modules\Core\Address\Models\Address::create([
        'line1' => '123 Main St',
        'line2' => 'Suite 100',
        'locality' => 'Springfield',
        'postcode' => '62701',
        'country_iso' => null,
    ]);

    $company->addresses()->attach($address->id, [
        'kind' => 'office',
        'is_primary' => true,
        'priority' => 0,
    ]);

    $fullAddress = $company->fresh()->fullAddress();

    expect($fullAddress)
        ->toContain('123 Main St')
        ->toContain('Suite 100')
        ->toContain('Springfield')
        ->toContain('62701');
});

test('active scope filters active companies', function (): void {
    $before = Company::query()->active()->count();

    Company::factory()->active()->count(3)->create();
    Company::factory()->suspended()->count(2)->create();
    Company::factory()->archived()->count(1)->create();

    $activeCompanies = Company::query()->active()->get();

    expect($activeCompanies)->toHaveCount($before + 3);
});

test('root scope filters companies without parent', function (): void {
    $before = Company::query()->root()->count();

    Company::factory()->count(3)->create(['parent_id' => null]);
    $parent = Company::factory()->create();
    Company::factory()->count(2)->create(['parent_id' => $parent->id]);

    $rootCompanies = Company::query()->root()->get();

    expect($rootCompanies)->toHaveCount($before + 4);
});

test('company can have relationships with other companies', function (): void {
    $company1 = Company::factory()->create();
    $company2 = Company::factory()->create();
    $relationshipType = RelationshipType::factory()->create();

    CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $relationshipType->id,
        'effective_from' => now(),
    ]);

    expect($company1->relationships)
        ->toHaveCount(1)
        ->and($company1->relationships->first()->related_company_id)
        ->toBe($company2->id);
});

test('company can have active relationships', function (): void {
    $company = Company::factory()->create();
    $relatedCompany = Company::factory()->create();
    $relationshipType = RelationshipType::factory()->create();

    // Active relationship
    CompanyRelationship::create([
        'company_id' => $company->id,
        'related_company_id' => $relatedCompany->id,
        'relationship_type_id' => $relationshipType->id,
        'effective_from' => now()->subDays(10),
        'effective_to' => now()->addDays(10),
    ]);

    // Expired relationship
    CompanyRelationship::create([
        'company_id' => $company->id,
        'related_company_id' => $relatedCompany->id,
        'relationship_type_id' => $relationshipType->id,
        'effective_from' => now()->subDays(30),
        'effective_to' => now()->subDays(5),
    ]);

    expect($company->activeRelationships)->toHaveCount(1);
});
