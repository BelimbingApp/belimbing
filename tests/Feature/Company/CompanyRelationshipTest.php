<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\CompanyRelationship;
use App\Modules\Core\Company\Models\RelationshipType;

test('relationship is active when within valid period', function (): void {
    [$company1, $company2, $type] = createCompanyRelationshipFixture();

    $relationship = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $type->id,
        'effective_from' => now()->subDays(10),
        'effective_to' => now()->addDays(10),
    ]);

    expect($relationship->isActive())->toBeTrue();
});

test('relationship is not active when before start date', function (): void {
    [$company1, $company2, $type] = createCompanyRelationshipFixture();

    $relationship = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $type->id,
        'effective_from' => now()->addDays(5),
        'effective_to' => now()->addDays(15),
    ]);

    expect($relationship->isActive())->toBeFalse()
        ->and($relationship->isPending())->toBeTrue();
});

test('relationship is not active when after end date', function (): void {
    [$company1, $company2, $type] = createCompanyRelationshipFixture();

    $relationship = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $type->id,
        'effective_from' => now()->subDays(20),
        'effective_to' => now()->subDays(5),
    ]);

    expect($relationship->isActive())->toBeFalse()
        ->and($relationship->hasEnded())->toBeTrue();
});

test('relationship with no end date is active', function (): void {
    [$company1, $company2, $type] = createCompanyRelationshipFixture();

    $relationship = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $type->id,
        'effective_from' => now()->subDays(10),
        'effective_to' => null,
    ]);

    expect($relationship->isActive())->toBeTrue()
        ->and($relationship->hasEnded())->toBeFalse();
});

test('relationship with no start date is active', function (): void {
    [$company1, $company2, $type] = createCompanyRelationshipFixture();

    $relationship = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $type->id,
        'effective_from' => null,
        'effective_to' => now()->addDays(10),
    ]);

    expect($relationship->isActive())->toBeTrue()
        ->and($relationship->hasStarted())->toBeTrue();
});

test('relationship can be ended', function (): void {
    [$company1, $company2, $type] = createCompanyRelationshipFixture();

    $relationship = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $type->id,
        'effective_from' => now()->subDays(10),
        'effective_to' => null,
    ]);

    expect($relationship->isActive())->toBeTrue();

    $relationship->end();

    expect($relationship->isActive())->toBeFalse()
        ->and($relationship->hasEnded())->toBeTrue();
});

test('relationship can be extended', function (): void {
    [$company1, $company2, $type] = createCompanyRelationshipFixture();

    $originalEndDate = now()->addDays(10);
    $relationship = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $type->id,
        'effective_from' => now()->subDays(10),
        'effective_to' => $originalEndDate,
    ]);

    $newEndDate = now()->addDays(30);
    $relationship->extendTo($newEndDate->toDateString());

    expect($relationship->effective_to->toDateString())
        ->toBe($newEndDate->toDateString());
});

test('relationship can be made indefinite', function (): void {
    [$company1, $company2, $type] = createCompanyRelationshipFixture();

    $relationship = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $type->id,
        'effective_from' => now()->subDays(10),
        'effective_to' => now()->addDays(10),
    ]);

    expect($relationship->effective_to)->not->toBeNull();

    $relationship->makeIndefinite();

    expect($relationship->effective_to)->toBeNull();
});

test('active scope filters only active relationships', function (): void {
    $before = CompanyRelationship::query()->active()->count();

    [$company1, $company2, $type] = createCompanyRelationshipFixture();

    // Active
    $active = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $type->id,
        'effective_from' => now()->subDays(10),
        'effective_to' => now()->addDays(10),
    ]);

    // Ended
    $ended = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $type->id,
        'effective_from' => now()->subDays(30),
        'effective_to' => now()->subDays(5),
    ]);

    // Pending
    $pending = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $type->id,
        'effective_from' => now()->addDays(5),
        'effective_to' => now()->addDays(15),
    ]);

    $activeRelationships = CompanyRelationship::query()->active()->get();

    expect($activeRelationships)->toHaveCount($before + 1)
        ->and($activeRelationships->contains('id', $active->id))->toBeTrue()
        ->and($activeRelationships->contains('id', $ended->id))->toBeFalse()
        ->and($activeRelationships->contains('id', $pending->id))->toBeFalse();
});

test('of type scope filters relationships by type code', function (): void {
    $beforeCustomer = CompanyRelationship::query()->ofType('customer')->count();
    $beforeSupplier = CompanyRelationship::query()->ofType('supplier')->count();

    $company1 = Company::factory()->create();
    $company2 = Company::factory()->create();
    $customerType = RelationshipType::query()->firstOrCreate(['code' => 'customer'], RelationshipType::factory()->customer()->raw());
    $supplierType = RelationshipType::query()->firstOrCreate(['code' => 'supplier'], RelationshipType::factory()->supplier()->raw());

    $customerRelationship = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $customerType->id,
    ]);

    $supplierRelationship = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $supplierType->id,
    ]);

    $customerRelationships = CompanyRelationship::query()->ofType('customer')->get();
    $supplierRelationships = CompanyRelationship::query()->ofType('supplier')->get();

    expect($customerRelationships)->toHaveCount($beforeCustomer + 1)
        ->and($customerRelationships->contains('id', $customerRelationship->id))->toBeTrue()
        ->and($customerRelationships->contains('id', $supplierRelationship->id))->toBeFalse()
        ->and($supplierRelationships)->toHaveCount($beforeSupplier + 1)
        ->and($supplierRelationships->contains('id', $supplierRelationship->id))->toBeTrue()
        ->and($supplierRelationships->contains('id', $customerRelationship->id))->toBeFalse();
});

test('external scope filters only external relationships', function (): void {
    $before = CompanyRelationship::query()->external()->count();

    $company1 = Company::factory()->create();
    $company2 = Company::factory()->create();
    $externalType = RelationshipType::factory()->external()->create();
    $internalType = RelationshipType::factory()->internal()->create();

    $externalRelationship = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $externalType->id,
    ]);

    $internalRelationship = CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $internalType->id,
    ]);

    $externalRelationships = CompanyRelationship::query()->external()->get();

    expect($externalRelationships)->toHaveCount($before + 1)
        ->and($externalRelationships->contains('id', $externalRelationship->id))->toBeTrue()
        ->and($externalRelationships->contains('id', $internalRelationship->id))->toBeFalse();
});

test('same companies can have multiple relationship types', function (): void {
    $company1 = Company::factory()->create();
    $company2 = Company::factory()->create();
    $customerType = RelationshipType::query()->firstOrCreate(['code' => 'customer'], RelationshipType::factory()->customer()->raw());
    $supplierType = RelationshipType::query()->firstOrCreate(['code' => 'supplier'], RelationshipType::factory()->supplier()->raw());

    CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $customerType->id,
        'effective_from' => now(),
    ]);

    CompanyRelationship::create([
        'company_id' => $company1->id,
        'related_company_id' => $company2->id,
        'relationship_type_id' => $supplierType->id,
        'effective_from' => now(),
    ]);

    $relationships = CompanyRelationship::query()
        ->where('company_id', $company1->id)
        ->where('related_company_id', $company2->id)
        ->get();

    expect($relationships)->toHaveCount(2);
});
