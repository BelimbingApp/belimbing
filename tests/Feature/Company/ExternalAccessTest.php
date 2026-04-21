<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Company\Models\CompanyRelationship;
use App\Modules\Core\Company\Models\ExternalAccess;
use App\Modules\Core\Company\Models\RelationshipType;
use App\Modules\Core\User\Models\User;

test('external access is valid when active and within date range', function (): void {
    $company = Company::factory()->create();
    $relatedCompany = Company::factory()->create();
    $type = RelationshipType::factory()->create();
    $user = User::factory()->create();

    $relationship = CompanyRelationship::create([
        'company_id' => $company->id,
        'related_company_id' => $relatedCompany->id,
        'relationship_type_id' => $type->id,
    ]);

    $access = ExternalAccess::create([
        'company_id' => $company->id,
        'relationship_id' => $relationship->id,
        'user_id' => $user->id,
        'is_active' => true,
        'access_granted_at' => now()->subDays(5),
        'access_expires_at' => now()->addDays(10),
    ]);

    expect($access->isValid())->toBeTrue();
});

test('external access is not valid when inactive', function (): void {
    $company = Company::factory()->create();
    $relatedCompany = Company::factory()->create();
    $type = RelationshipType::factory()->create();
    $user = User::factory()->create();

    $relationship = CompanyRelationship::create([
        'company_id' => $company->id,
        'related_company_id' => $relatedCompany->id,
        'relationship_type_id' => $type->id,
    ]);

    $access = ExternalAccess::create([
        'company_id' => $company->id,
        'relationship_id' => $relationship->id,
        'user_id' => $user->id,
        'is_active' => false,
        'access_granted_at' => now()->subDays(5),
        'access_expires_at' => now()->addDays(10),
    ]);

    expect($access->isValid())->toBeFalse();
});

test('external access is not valid when expired', function (): void {
    $company = Company::factory()->create();
    $relatedCompany = Company::factory()->create();
    $type = RelationshipType::factory()->create();
    $user = User::factory()->create();

    $relationship = CompanyRelationship::create([
        'company_id' => $company->id,
        'related_company_id' => $relatedCompany->id,
        'relationship_type_id' => $type->id,
    ]);

    $access = ExternalAccess::create([
        'company_id' => $company->id,
        'relationship_id' => $relationship->id,
        'user_id' => $user->id,
        'is_active' => true,
        'access_granted_at' => now()->subDays(20),
        'access_expires_at' => now()->subDays(5),
    ]);

    expect($access->isValid())->toBeFalse()
        ->and($access->hasExpired())->toBeTrue();
});

test('external access is not valid when pending grant', function (): void {
    $company = Company::factory()->create();
    $relatedCompany = Company::factory()->create();
    $type = RelationshipType::factory()->create();
    $user = User::factory()->create();

    $relationship = CompanyRelationship::create([
        'company_id' => $company->id,
        'related_company_id' => $relatedCompany->id,
        'relationship_type_id' => $type->id,
    ]);

    $access = ExternalAccess::create([
        'company_id' => $company->id,
        'relationship_id' => $relationship->id,
        'user_id' => $user->id,
        'is_active' => true,
        'access_granted_at' => now()->addDays(5),
        'access_expires_at' => now()->addDays(20),
    ]);

    expect($access->isValid())->toBeFalse()
        ->and($access->isPending())->toBeTrue();
});

test('external access with no expiration is valid', function (): void {
    $company = Company::factory()->create();
    $relatedCompany = Company::factory()->create();
    $type = RelationshipType::factory()->create();
    $user = User::factory()->create();

    $relationship = CompanyRelationship::create([
        'company_id' => $company->id,
        'related_company_id' => $relatedCompany->id,
        'relationship_type_id' => $type->id,
    ]);

    $access = ExternalAccess::create([
        'company_id' => $company->id,
        'relationship_id' => $relationship->id,
        'user_id' => $user->id,
        'is_active' => true,
        'access_granted_at' => now()->subDays(5),
        'access_expires_at' => null,
    ]);

    expect($access->isValid())->toBeTrue()
        ->and($access->hasExpired())->toBeFalse();
});

test('external access can be granted', function (): void {
    $company = Company::factory()->create();
    $relatedCompany = Company::factory()->create();
    $type = RelationshipType::factory()->create();
    $user = User::factory()->create();

    $relationship = CompanyRelationship::create([
        'company_id' => $company->id,
        'related_company_id' => $relatedCompany->id,
        'relationship_type_id' => $type->id,
    ]);

    $access = ExternalAccess::create([
        'company_id' => $company->id,
        'relationship_id' => $relationship->id,
        'user_id' => $user->id,
        'is_active' => false,
        'access_granted_at' => null,
    ]);

    expect($access->is_active)->toBeFalse();

    $access->grant();

    expect($access->is_active)->toBeTrue()
        ->and($access->access_granted_at)->not->toBeNull();
});

test('external access can grant permission', function (): void {
    $company = Company::factory()->create();
    $relatedCompany = Company::factory()->create();
    $type = RelationshipType::factory()->create();
    $user = User::factory()->create();

    $relationship = CompanyRelationship::create([
        'company_id' => $company->id,
        'related_company_id' => $relatedCompany->id,
        'relationship_type_id' => $type->id,
    ]);

    $access = ExternalAccess::create([
        'company_id' => $company->id,
        'relationship_id' => $relationship->id,
        'user_id' => $user->id,
        'permissions' => ['view_orders'],
        'is_active' => true,
    ]);

    expect($access->hasPermission('edit_orders'))->toBeFalse();

    $access->grantPermission('edit_orders');

    expect($access->hasPermission('edit_orders'))->toBeTrue()
        ->and($access->permissions)->toHaveCount(2);
});

test('external access can revoke permission', function (): void {
    $company = Company::factory()->create();
    $relatedCompany = Company::factory()->create();
    $type = RelationshipType::factory()->create();
    $user = User::factory()->create();

    $relationship = CompanyRelationship::create([
        'company_id' => $company->id,
        'related_company_id' => $relatedCompany->id,
        'relationship_type_id' => $type->id,
    ]);

    $access = ExternalAccess::create([
        'company_id' => $company->id,
        'relationship_id' => $relationship->id,
        'user_id' => $user->id,
        'permissions' => ['view_orders', 'edit_orders'],
        'is_active' => true,
    ]);

    expect($access->hasPermission('edit_orders'))->toBeTrue();

    $access->revokePermission('edit_orders');

    expect($access->hasPermission('edit_orders'))->toBeFalse()
        ->and($access->permissions)->toHaveCount(1);
});

test('valid scope filters only valid accesses', function (): void {
    $company = Company::factory()->create();
    $relatedCompany = Company::factory()->create();
    $type = RelationshipType::factory()->create();
    $user = User::factory()->create();

    $relationship = CompanyRelationship::create([
        'company_id' => $company->id,
        'related_company_id' => $relatedCompany->id,
        'relationship_type_id' => $type->id,
    ]);

    // Valid
    $valid = ExternalAccess::create([
        'company_id' => $company->id,
        'relationship_id' => $relationship->id,
        'user_id' => $user->id,
        'is_active' => true,
        'access_granted_at' => now()->subDays(5),
        'access_expires_at' => now()->addDays(10),
    ]);

    // Inactive
    $inactive = ExternalAccess::create([
        'company_id' => $company->id,
        'relationship_id' => $relationship->id,
        'user_id' => $user->id,
        'is_active' => false,
        'access_granted_at' => now()->subDays(5),
        'access_expires_at' => now()->addDays(10),
    ]);

    // Expired
    $expired = ExternalAccess::create([
        'company_id' => $company->id,
        'relationship_id' => $relationship->id,
        'user_id' => $user->id,
        'is_active' => true,
        'access_granted_at' => now()->subDays(20),
        'access_expires_at' => now()->subDays(5),
    ]);

    $validAccesses = ExternalAccess::query()->valid()->get();

    expect($validAccesses)->toHaveCount(1)
        ->and($validAccesses->contains('id', $valid->id))->toBeTrue()
        ->and($validAccesses->contains('id', $inactive->id))->toBeFalse()
        ->and($validAccesses->contains('id', $expired->id))->toBeFalse();
});
