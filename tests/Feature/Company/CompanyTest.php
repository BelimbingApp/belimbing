<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantContextMissingException;
use App\Base\Tenancy\Models\Tenant;
use App\Core\Address\Models\Address;
use App\Core\Company\Exceptions\CompanyTenantAssignmentException;
use App\Core\Company\Exceptions\PrimaryCompanyAssignmentException;
use App\Core\Company\Exceptions\PrimaryCompanyDeletionException;
use App\Core\Company\Exceptions\PrimaryCompanyInvariantViolationException;
use App\Core\Company\Exceptions\PrimaryCompanyNotProvisionedException;
use App\Core\Company\Models\Company;
use App\Core\Company\Models\CompanyRelationship;
use App\Core\Company\Models\RelationshipType;
use App\Core\Company\Models\TenantPrimaryCompany;
use App\Core\Company\Services\PrimaryCompanyManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('company code is auto-generated from name', function (): void {
    $company = Company::factory()->create([
        'name' => 'My Great Company',
    ]);

    expect($company->code)->toBe('my_great_company');
});

test('two customer tenants can each have an explicit primary company', function (): void {
    $manager = app(PrimaryCompanyManager::class);
    $tenantA = $manager->provisionTenant(['name' => 'Tenant A', 'status' => 'active'], ['name' => 'Tenant A Primary']);
    $tenantB = $manager->provisionTenant(['name' => 'Tenant B', 'status' => 'active'], ['name' => 'Tenant B Primary']);

    expect($manager->requireForTenant($tenantA)->tenant_id)->toBe($tenantA->id)
        ->and($manager->requireForTenant($tenantB)->tenant_id)->toBe($tenantB->id)
        ->and($manager->requireForTenant($tenantA)->id)->not->toBe($manager->requireForTenant($tenantB)->id);
});

test('a company cannot become the primary company of a different tenant', function (): void {
    [, $companyA] = createTenantWithCompany(['name' => 'Tenant A']);
    $tenantB = createTenant(['name' => 'Tenant B']);

    app(PrimaryCompanyManager::class)->assign($tenantB, $companyA);
})->throws(PrimaryCompanyAssignmentException::class);

test('database constraints prevent a company from becoming primary for two tenants', function (): void {
    [$tenantA, $companyA] = createTenantWithCompany(['name' => 'Tenant A']);
    $tenantB = createTenant(['name' => 'Tenant B']);
    app(PrimaryCompanyManager::class)->assign($tenantA, $companyA);

    DB::table('tenant_primary_companies')->insert([
        'tenant_id' => $tenantB->id,
        'company_id' => $companyA->id,
    ]);
})->throws(QueryException::class);

test('company creation without an explicit tenant or tenant context fails closed', function (): void {
    app(TenantContext::class)->clear();

    Company::query()->create(['name' => 'Unscoped Company']);
})->throws(TenantContextMissingException::class);

test('database has no fallback tenant assignment for companies', function (): void {
    DB::table('companies')->insert([
        'name' => 'Database-unscoped Company',
        'code' => 'database_unscoped_company',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

test('a company tenant assignment cannot change after creation', function (): void {
    [, $company] = createTenantWithCompany(['name' => 'Tenant A']);
    $tenantB = createTenant(['name' => 'Tenant B']);

    $company->tenant_id = $tenantB->id;
    $company->save();
})->throws(CompanyTenantAssignmentException::class);

test('a parent company must belong to the same tenant', function (): void {
    [, $parent] = createTenantWithCompany(['name' => 'Tenant A']);
    $tenantB = createTenant(['name' => 'Tenant B']);

    Company::factory()->create([
        'tenant_id' => $tenantB->id,
        'parent_id' => $parent->id,
    ]);
})->throws(CompanyTenantAssignmentException::class);

test('a soft-deleted primary company is reported as an invariant violation', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    app(PrimaryCompanyManager::class)->assign($tenant, $company);
    DB::table('companies')->where('id', $company->id)->update(['deleted_at' => now()]);

    app(PrimaryCompanyManager::class)->requireForTenant($tenant);
})->throws(PrimaryCompanyInvariantViolationException::class);

test('a tenant without a primary company is explicitly not yet provisioned', function (): void {
    app(PrimaryCompanyManager::class)->requireForTenant(createTenant());
})->throws(PrimaryCompanyNotProvisionedException::class);

test('legacy operator company id 1 is deterministically backfilled without changing ids', function (): void {
    TenantPrimaryCompany::query()->delete();
    DB::table('tenants')->where('is_platform_operator', true)->update(['is_platform_operator' => false]);
    DB::table('tenants')->insert([
        'id' => 1,
        'name' => 'Legacy Operator Tenant',
        'status' => 'active',
        'is_platform_operator' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('companies')->where('id', 1)->update(['tenant_id' => 1]);

    $operator = Tenant::query()->findOrFail(1);
    $legacyCompany = Company::query()->findOrFail(1);
    Company::factory()->create(['tenant_id' => $operator->id]);

    $migration = require app_path('Core/Company/Database/Migrations/0200_01_07_001006_backfill_tenant_primary_companies.php');
    $migration->up();

    expect($operator->id)->toBe(1)
        ->and($legacyCompany->id)->toBe(1)
        ->and(app(PrimaryCompanyManager::class)->requireForTenant($operator)->is($legacyCompany))->toBeTrue();
});

test('primary-company migration preflight rejects ambiguous customer tenants before writing', function (): void {
    $tenant = createTenant(['name' => 'Ambiguous Tenant']);
    Company::factory()->count(2)->create(['tenant_id' => $tenant->id]);
    TenantPrimaryCompany::query()->delete();
    $migration = require app_path('Core/Company/Database/Migrations/0200_01_07_001006_backfill_tenant_primary_companies.php');

    expect(fn () => $migration->up())->toThrow(
        RuntimeException::class,
        "tenant {$tenant->id} has candidates",
    );
    expect(TenantPrimaryCompany::query()->exists())->toBeFalse();
});

test('primary-company migration honors an explicit designation for an ambiguous tenant', function (): void {
    $tenant = createTenant(['name' => 'Explicitly Designated Tenant']);
    $designated = Company::factory()->create(['tenant_id' => $tenant->id]);
    Company::factory()->create(['tenant_id' => $tenant->id]);
    TenantPrimaryCompany::query()->create([
        'tenant_id' => $tenant->id,
        'company_id' => $designated->id,
    ]);
    $migration = require app_path('Core/Company/Database/Migrations/0200_01_07_001006_backfill_tenant_primary_companies.php');

    $migration->up();

    expect(app(PrimaryCompanyManager::class)->requireForTenant($tenant)->is($designated))->toBeTrue();
});

test('primary-company migration treats all live companies as candidates regardless of status', function (): void {
    $tenant = createTenant(['name' => 'Suspended-company Tenant']);
    $company = Company::factory()->suspended()->create(['tenant_id' => $tenant->id]);
    TenantPrimaryCompany::query()->delete();
    $migration = require app_path('Core/Company/Database/Migrations/0200_01_07_001006_backfill_tenant_primary_companies.php');

    $migration->up();

    expect(app(PrimaryCompanyManager::class)->requireForTenant($tenant)->is($company))->toBeTrue();
});

test('primary-company migration rejects mixed-status ambiguity before writing', function (): void {
    $tenant = createTenant(['name' => 'Mixed-status Tenant']);
    Company::factory()->active()->create(['tenant_id' => $tenant->id]);
    Company::factory()->suspended()->create(['tenant_id' => $tenant->id]);
    TenantPrimaryCompany::query()->delete();
    $migration = require app_path('Core/Company/Database/Migrations/0200_01_07_001006_backfill_tenant_primary_companies.php');

    expect(fn () => $migration->up())->toThrow(
        RuntimeException::class,
        "tenant {$tenant->id} has candidates",
    );
    expect(TenantPrimaryCompany::query()->exists())->toBeFalse();
});

test('primary-company migration leaves tenants with no live companies unprovisioned', function (): void {
    $tenant = createTenant(['name' => 'Companyless Tenant']);
    TenantPrimaryCompany::query()->delete();
    $migration = require app_path('Core/Company/Database/Migrations/0200_01_07_001006_backfill_tenant_primary_companies.php');

    $migration->up();

    expect(TenantPrimaryCompany::query()->where('tenant_id', $tenant->id)->exists())->toBeFalse();
});

test('primary-company migration rollback does not erase operational assignments', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Operational Tenant']);
    app(PrimaryCompanyManager::class)->assign($tenant, $company);
    $migration = require app_path('Core/Company/Database/Migrations/0200_01_07_001006_backfill_tenant_primary_companies.php');

    $migration->down();

    expect(app(PrimaryCompanyManager::class)->requireForTenant($tenant)->is($company))->toBeTrue();
});

test('a primary company cannot be deleted before an explicit transfer', function (): void {
    [$tenant, $company] = createTenantWithCompany();
    app(PrimaryCompanyManager::class)->assign($tenant, $company);

    $company->delete();
})->throws(PrimaryCompanyDeletionException::class);

test('transferring the primary role allows the former primary company to be deleted', function (): void {
    [$tenant, $first] = createTenantWithCompany();
    $second = Company::factory()->create(['tenant_id' => $tenant->id]);
    $manager = app(PrimaryCompanyManager::class);
    $manager->assign($tenant, $first);
    $manager->transfer($tenant, $second);

    $first->delete();

    expect(Company::query()->find($first->id))->toBeNull()
        ->and(Company::withTrashed()->find($first->id)?->trashed())->toBeTrue()
        ->and($manager->requireForTenant($tenant)->is($second))->toBeTrue();
});

test('deletion updates the invoking company instance after a primary transfer', function (): void {
    [$tenant, $first] = createTenantWithCompany();
    $second = Company::factory()->create(['tenant_id' => $tenant->id]);
    $manager = app(PrimaryCompanyManager::class);
    $manager->assign($tenant, $first);
    $manager->transfer($tenant, $second);

    $first->delete();

    expect($first->trashed())->toBeTrue();
});

test('the former primary company can be force deleted after a transfer', function (): void {
    [$tenant, $first] = createTenantWithCompany();
    $second = Company::factory()->create(['tenant_id' => $tenant->id]);
    $manager = app(PrimaryCompanyManager::class);
    $manager->assign($tenant, $first);
    $manager->transfer($tenant, $second);

    $first->forceDelete();

    expect(Company::withTrashed()->find($first->id))->toBeNull()
        ->and($manager->requireForTenant($tenant)->is($second))->toBeTrue();
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

    $address = Address::create([
        'tenant_id' => $company->tenant_id,
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
