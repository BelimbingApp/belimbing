<?php

use App\Base\Tenancy\Models\Tenant;
use App\Core\Company\Exceptions\CompanyErasureException;
use App\Core\Company\Models\Company;
use App\Core\Company\Services\PrimaryCompanyManager;

/**
 * Erasing a company must not shrink the list of companies its tenant has held.
 *
 * Soft deletion retires a company and leaves the row behind, so anything that
 * asks "which companies has this tenant had?" keeps getting the true answer.
 * Hard deletion removes the row, and the row is the only record that the
 * company ever existed. These tests hold the line between the two.
 */

/**
 * Two platform companies in one tenant, the first of them primary.
 *
 * @return array{Tenant, Company, Company}
 */
function tenantHoldingTwoCompanies(string $name = 'Two Company Tenant'): array
{
    $tenant = createTenant(['name' => $name]);
    $alpha = Company::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Alpha Industries']);
    $beta = Company::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Beta Works']);

    app(PrimaryCompanyManager::class)->assign($tenant, $alpha);

    return [$tenant, $alpha, $beta];
}

/**
 * The downstream rule this guard exists for, restated in Core's own terms.
 *
 * `BelimbingApp/blb-people-connector` grants a *tenant-scoped* provider
 * connection - one that names no platform company, which is a normal and
 * permitted deployment - the right to attribute every workforce company it
 * carries to the acting user's platform company, but only while the tenant has
 * held exactly one company. With two companies there is no way to tell which
 * one a workforce company belongs to, so the connector hands back nothing.
 *
 * The connector counts soft-deleted companies deliberately, so retiring a
 * company cannot reopen that carve-out. Hard deletion removes the row the
 * count is made of, which is the hole this file covers.
 *
 * The connector is not installed here and Core must not depend on it, so the
 * rule is written out rather than imported. Nothing about it is connector
 * specific: any rule that reads authority off a count of a tenant's companies
 * breaks in exactly this way.
 *
 * @param  array{company_id: int|null}  $connection
 * @param  list<string>  $workforceCompanies  everything that connection carries
 * @return list<string> what the actor's company may resolve
 */
function workforceCompaniesResolvedFor(Company $actorCompany, array $connection, array $workforceCompanies): array
{
    $tenantId = (int) $actorCompany->tenant_id;

    $attributable = $connection['company_id'] === null
        ? Company::withTrashed()->where('tenant_id', $tenantId)->count() === 1
        : $connection['company_id'] === (int) $actorCompany->id;

    return $attributable ? $workforceCompanies : [];
}

test('erasing a company cannot widen who resolves the tenant workforce', function (): void {
    [$tenant, $alpha, $beta] = tenantHoldingTwoCompanies('Workforce Widening Tenant');

    // One tenant-scoped connection carrying both companies' workforce. It
    // names no platform company, and nothing in the schema forbids that.
    $connection = ['company_id' => null];
    $workforce = ['alpha-workforce-company', 'beta-workforce-company'];

    // The carve-out is shut, because the tenant holds two companies.
    expect(workforceCompaniesResolvedFor($alpha, $connection, $workforce))->toBe([]);

    // Erasing the second company would take the count back to one.
    expect(fn () => $beta->forceDelete())->toThrow(CompanyErasureException::class);

    // So the count never moved, and the carve-out never reopened. Alpha's
    // users still resolve nothing, including none of beta's workforce.
    expect(Company::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(Company::withTrashed()->find($beta->id))->not->toBeNull()
        ->and(workforceCompaniesResolvedFor($alpha, $connection, $workforce))->toBe([]);
});

test('a company cannot be erased once its tenant has held another company', function (): void {
    [$tenant, , $beta] = tenantHoldingTwoCompanies('Erasure Refusal Tenant');

    expect(fn () => $beta->forceDelete())->toThrow(CompanyErasureException::class);

    expect(Company::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('retiring a company is still allowed and keeps the tenant company count intact', function (): void {
    [$tenant, $alpha, $beta] = tenantHoldingTwoCompanies('Retirement Control Tenant');
    $connection = ['company_id' => null];
    $workforce = ['alpha-workforce-company', 'beta-workforce-company'];

    $beta->delete();

    expect($beta->trashed())->toBeTrue()
        ->and(Company::query()->find($beta->id))->toBeNull()
        ->and(Company::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(workforceCompaniesResolvedFor($alpha, $connection, $workforce))->toBe([]);
});

test('a retired company still cannot be erased', function (): void {
    [$tenant, , $beta] = tenantHoldingTwoCompanies('Retired Then Erased Tenant');

    $beta->delete();

    expect(fn () => $beta->forceDelete())->toThrow(CompanyErasureException::class);

    expect(Company::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('the query builder cannot erase a company the model refuses to erase', function (): void {
    [$tenant, , $beta] = tenantHoldingTwoCompanies('Builder Bypass Tenant');

    expect(fn () => Company::withTrashed()->whereKey($beta->id)->forceDelete())
        ->toThrow(CompanyErasureException::class);

    expect(Company::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(2);
});

test('a tenant that has only ever held one company is what the rule relaxes for', function (): void {
    [, $only] = createTenantWithCompany(['name' => 'Sole Company Tenant']);
    $connection = ['company_id' => null];
    $workforce = ['the-whole-workforce'];

    // With one company there is no internal boundary to cross, so the rule
    // hands the actor everything. This is the state an erasure would fake.
    expect(workforceCompaniesResolvedFor($only, $connection, $workforce))->toBe($workforce);
});

test('a company its tenant has never held a second of can still be erased', function (): void {
    [$tenant, $only] = createTenantWithCompany(['name' => 'Erasable Sole Company Tenant']);

    expect($only->forceDelete())->toBeTrue()
        ->and(Company::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('erasing an already retired company really removes the row', function (): void {
    [$tenant, $only] = createTenantWithCompany(['name' => 'Retired Then Erasable Tenant']);

    $only->delete();

    // The erase must reach a row that is already soft-deleted. A guard that
    // quietly matched nothing would leave the row behind while the model
    // claimed it was gone.
    expect($only->forceDelete())->toBeTrue()
        ->and($only->exists)->toBeFalse()
        ->and(Company::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('the query builder erases an already retired company too', function (): void {
    [$tenant, $only] = createTenantWithCompany(['name' => 'Builder Retired Erasable Tenant']);

    $only->delete();

    expect(Company::query()->whereKey($only->id)->forceDelete())->toBe(1)
        ->and(Company::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

test('the erasure refusal names the tenant and the company it protected', function (): void {
    [$tenant, , $beta] = tenantHoldingTwoCompanies('Erasure Context Tenant');

    try {
        $beta->forceDelete();
        $this->fail('The erasure should have been refused.');
    } catch (CompanyErasureException $exception) {
        expect($exception->context)->toBe([
            'tenant_id' => (int) $tenant->id,
            'company_id' => (int) $beta->id,
            'companies_held_by_tenant' => 2,
        ]);
    }
});
