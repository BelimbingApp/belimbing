<?php

use App\Core\AI\Models\AiProvider;
use App\Core\AI\Models\AiProviderModel;
use App\Core\AI\Services\ConfigResolver;
use App\Core\Company\Models\Company;
use App\Core\Company\Services\PrimaryCompanyManager;
use App\Core\Employee\Models\Employee;

const TENANT_AI_BASE_URL = 'https://tenant-ai.example.test';

function provisionTenantProvider(Company $company): void
{
    $provider = AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => 'openai',
        'display_name' => 'OpenAI',
        'base_url' => TENANT_AI_BASE_URL,
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'tenant-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);

    AiProviderModel::query()->create([
        'ai_provider_id' => $provider->id,
        'model_id' => 'gpt-tenant',
        'is_active' => true,
        'is_default' => true,
    ]);
}

it('resolves the tenant primary company provider for companies without their own', function (): void {
    [$tenant, $primaryCompany] = createTenantWithCompany(['name' => 'AI Tenant']);
    $sub = Company::factory()->create(['tenant_id' => $tenant->id]);

    app(PrimaryCompanyManager::class)->assign($tenant, $primaryCompany);
    provisionTenantProvider($primaryCompany);

    $employee = Employee::factory()->create(['company_id' => $sub->id]);

    $config = app(ConfigResolver::class)->resolveDefault((int) $employee->getKey());

    expect($config)->not->toBeNull();
    expect($config['base_url'])->toBe(TENANT_AI_BASE_URL);
    expect($config['model'])->toBe('gpt-tenant');
});

it('prefers the company own provider over the tenant primary company', function (): void {
    [$tenant, $primaryCompany] = createTenantWithCompany(['name' => 'AI Tenant']);
    $sub = Company::factory()->create(['tenant_id' => $tenant->id]);

    app(PrimaryCompanyManager::class)->assign($tenant, $primaryCompany);
    provisionTenantProvider($primaryCompany);

    $ownProvider = AiProvider::query()->create([
        'company_id' => $sub->id,
        'name' => 'anthropic',
        'display_name' => 'Anthropic',
        'base_url' => 'https://own.example.test',
        'auth_type' => 'api_key',
        'credentials' => ['api_key' => 'own-key'],
        'connection_config' => [],
        'is_active' => true,
        'priority' => 1,
    ]);
    AiProviderModel::query()->create([
        'ai_provider_id' => $ownProvider->id,
        'model_id' => 'claude-own',
        'is_active' => true,
        'is_default' => true,
    ]);

    $employee = Employee::factory()->create(['company_id' => $sub->id]);

    $config = app(ConfigResolver::class)->resolveDefault((int) $employee->getKey());

    expect($config['base_url'])->toBe('https://own.example.test');
    expect($config['model'])->toBe('claude-own');
});

it('returns null when no company in the tenant has providers', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Empty Tenant']);
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    expect(app(ConfigResolver::class)->resolveDefault((int) $employee->getKey()))->toBeNull();
});

it('never resolves providers across tenant boundaries', function (): void {
    [$tenantA, $primaryCompanyA] = createTenantWithCompany(['name' => 'Tenant A']);
    app(PrimaryCompanyManager::class)->assign($tenantA, $primaryCompanyA);
    provisionTenantProvider($primaryCompanyA);

    [$tenantB, $companyB] = createTenantWithCompany(['name' => 'Tenant B']);
    $employee = Employee::factory()->create(['company_id' => $companyB->id]);

    expect(app(ConfigResolver::class)->resolveDefault((int) $employee->getKey()))->toBeNull();
});
