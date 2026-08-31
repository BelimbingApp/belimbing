<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\Exceptions\InvalidSettingScopeException;
use App\Base\Settings\Models\Setting;
use App\Base\Settings\Services\SettingDefinitionRegistry;
use App\Core\User\Models\User;

beforeEach(function (): void {
    // Definition keys contain literal dots: merge the map wholesale rather
    // than config()->set(), which would treat the key as dot notation.
    config()->set('settings.definitions', array_merge(
        (array) config('settings.definitions', []),
        [
            'zz_tenant.onboarding' => [
                'type' => 'string',
                'scopes' => ['global', 'tenant', 'company', 'user'],
                'default' => 'def',
                'owner' => 'tests',
            ],
            'zz_tenant.plain' => [
                'type' => 'string',
                'scopes' => ['global', 'company'],
                'default' => 'plain-def',
                'owner' => 'tests',
            ],
        ],
    ));

    // The registry memoizes discovered definitions; rebuild it (and the
    // scoped service holding it) so the fixture definitions are visible.
    app()->forgetInstance(SettingDefinitionRegistry::class);
    app()->forgetInstance(SettingsService::class);
});

function tenantCascadeFixtures(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Cascade Tenant']);
    $user = User::factory()->create(['employee_id' => null, 'company_id' => $company->id]);

    return [$tenant, $company, $user];
}

it('cascades user → company → tenant → global and writes to the most specific scope', function (): void {
    [$tenant, $company, $user] = tenantCascadeFixtures();
    $settings = app(SettingsService::class);

    $settings->set('zz_tenant.onboarding', 'glob');
    $userScope = Scope::user((int) $user->getKey(), $company->id, $tenant->id);

    expect($settings->get('zz_tenant.onboarding', $userScope))->toBe('glob');

    $settings->set('zz_tenant.onboarding', 'ten', Scope::tenant($tenant->id));
    expect($settings->get('zz_tenant.onboarding', $userScope))->toBe('ten');

    $settings->set('zz_tenant.onboarding', 'co', Scope::company($company->id));
    expect($settings->get('zz_tenant.onboarding', $userScope))->toBe('co');

    $settings->set('zz_tenant.onboarding', 'usr', $userScope);
    expect($settings->get('zz_tenant.onboarding', $userScope))->toBe('usr');

    // Writes land in the most specific scope only; fallback layers are untouched.
    expect(Setting::query()->where('key', 'zz_tenant.onboarding')->where('scope_type', 'tenant')->value('value'))->toBe('ten');
    expect(Setting::query()->where('key', 'zz_tenant.onboarding')->where('scope_type', 'company')->value('value'))->toBe('co');
    expect($settings->get('zz_tenant.onboarding', Scope::tenant($tenant->id)))->toBe('ten');
});

it('derives the tenant link from the company when the scope carries no tenant id', function (): void {
    [$tenant, $company, $user] = tenantCascadeFixtures();
    $settings = app(SettingsService::class);

    $settings->set('zz_tenant.onboarding', 'ten', Scope::tenant($tenant->id));

    // No tenantId passed: the chain resolves it through the TenantDirectory.
    expect($settings->get('zz_tenant.onboarding', Scope::company($company->id)))->toBe('ten');
    expect($settings->get('zz_tenant.onboarding', Scope::user((int) $user->getKey(), $company->id)))->toBe('ten');
});

it('keeps definitions without tenant scope on the pre-tenancy chain', function (): void {
    [$tenant] = tenantCascadeFixtures();
    $settings = app(SettingsService::class);

    // Tenant-scoped writes are rejected for definitions that do not allow it.
    $settings->set('zz_tenant.plain', 'ten', Scope::tenant($tenant->id));
})->throws(InvalidSettingScopeException::class);

it('ignores tenant rows for definitions that do not declare tenant scope', function (): void {
    [$tenant, $company] = tenantCascadeFixtures();
    $settings = app(SettingsService::class);

    Setting::query()->create([
        'key' => 'zz_tenant.plain',
        'scope_type' => 'tenant',
        'scope_id' => $tenant->id,
        'value' => 'ten',
    ]);

    expect($settings->get('zz_tenant.plain', Scope::company($company->id)))->toBe('plain-def');
});

it('does not cross tenant boundaries', function (): void {
    [$tenantA, $companyA] = tenantCascadeFixtures();
    [, $companyB] = createTenantWithCompany(['name' => 'Other Tenant']);
    $settings = app(SettingsService::class);

    $settings->set('zz_tenant.onboarding', 'tenA', Scope::tenant($tenantA->id));

    expect($settings->get('zz_tenant.onboarding', Scope::company($companyB->id)))->toBe('def');
    expect($settings->get('zz_tenant.onboarding', Scope::company($companyA->id)))->toBe('tenA');
});
