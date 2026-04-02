<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\Orchestration\AgentCapabilityDescriptor;
use App\Modules\Core\AI\Services\LaraCapabilityMatcher;
use App\Modules\Core\AI\Services\Orchestration\AgentCapabilityCatalog;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

const CATALOG_DOMAIN_IT_SUPPORT = 'it_support';
const CATALOG_DOMAIN_CODE_REVIEW = 'code_review';
const CATALOG_TASK_RESOLVE_TICKET = 'resolve_ticket';
const CATALOG_SPECIALTY_DATABASE = 'database_migration';
const CATALOG_DESIGNATION = 'IT Support Agent';
const CATALOG_JOB_DESCRIPTION = 'Handles IT support tickets and infrastructure issues';

/**
 * Create an active agent employee with optional structured capability metadata.
 *
 * @param  array<string, mixed>  $capabilities  Structured capability data for metadata['ai_capabilities']
 * @param  array<string, mixed>  $overrides  Additional Employee attribute overrides
 */
function createAgentWithCapabilities(Company $company, array $capabilities = [], array $overrides = []): Employee
{
    $metadata = $capabilities !== [] ? ['ai_capabilities' => $capabilities] : null;

    return Employee::factory()->create(array_merge([
        'company_id' => $company->id,
        'employee_type' => 'agent',
        'status' => 'active',
        'metadata' => $metadata,
    ], $overrides));
}

function makeCapabilityCatalog(): AgentCapabilityCatalog
{
    return new AgentCapabilityCatalog(new LaraCapabilityMatcher);
}

// --- descriptorFor ---

it('returns null for a non-existent employee', function (): void {
    $catalog = makeCapabilityCatalog();

    expect($catalog->descriptorFor(99999))->toBeNull();
});

it('returns null for a human employee', function (): void {
    $company = Company::factory()->create();
    Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'permanent',
        'status' => 'active',
    ]);

    $catalog = makeCapabilityCatalog();

    // Human employees should not appear in the agent capability catalog
    expect($catalog->descriptorFor(Employee::query()->where('employee_type', 'permanent')->first()->id))
        ->toBeNull();
});

it('returns null for an inactive agent', function (): void {
    $company = Company::factory()->create();
    $agent = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'agent',
        'status' => 'inactive',
    ]);

    $catalog = makeCapabilityCatalog();

    expect($catalog->descriptorFor($agent->id))->toBeNull();
});

it('builds a descriptor with structured capabilities from metadata', function (): void {
    $company = Company::factory()->create();
    $agent = createAgentWithCapabilities($company, [
        'domains' => [CATALOG_DOMAIN_IT_SUPPORT, CATALOG_DOMAIN_CODE_REVIEW],
        'task_types' => [CATALOG_TASK_RESOLVE_TICKET],
        'specialties' => [CATALOG_SPECIALTY_DATABASE],
        'tool_access' => ['query_data', 'edit_data'],
        'requires_human_review' => true,
        'meta' => ['maturity' => 'beta'],
    ], [
        'designation' => CATALOG_DESIGNATION,
        'job_description' => CATALOG_JOB_DESCRIPTION,
    ]);

    $catalog = makeCapabilityCatalog();
    $descriptor = $catalog->descriptorFor($agent->id);

    expect($descriptor)->toBeInstanceOf(AgentCapabilityDescriptor::class)
        ->and($descriptor->employeeId)->toBe($agent->id)
        ->and($descriptor->domains)->toBe([CATALOG_DOMAIN_IT_SUPPORT, CATALOG_DOMAIN_CODE_REVIEW])
        ->and($descriptor->taskTypes)->toBe([CATALOG_TASK_RESOLVE_TICKET])
        ->and($descriptor->specialties)->toBe([CATALOG_SPECIALTY_DATABASE])
        ->and($descriptor->toolAccess)->toBe(['query_data', 'edit_data'])
        ->and($descriptor->requiresHumanReview)->toBeTrue()
        ->and($descriptor->meta)->toBe(['maturity' => 'beta'])
        ->and($descriptor->hasStructuredCapabilities())->toBeTrue()
        ->and($descriptor->displaySummary)->toContain(CATALOG_DESIGNATION)
        ->and($descriptor->displaySummary)->toContain(CATALOG_JOB_DESCRIPTION);
});

it('builds a descriptor with empty capabilities when metadata has no ai_capabilities key', function (): void {
    $company = Company::factory()->create();
    $agent = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'agent',
        'status' => 'active',
        'metadata' => ['other_key' => 'value'],
        'designation' => CATALOG_DESIGNATION,
    ]);

    $catalog = makeCapabilityCatalog();
    $descriptor = $catalog->descriptorFor($agent->id);

    expect($descriptor)->toBeInstanceOf(AgentCapabilityDescriptor::class)
        ->and($descriptor->domains)->toBe([])
        ->and($descriptor->taskTypes)->toBe([])
        ->and($descriptor->specialties)->toBe([])
        ->and($descriptor->hasStructuredCapabilities())->toBeFalse()
        ->and($descriptor->displaySummary)->toBe(CATALOG_DESIGNATION);
});

it('builds a display summary from designation and job description', function (): void {
    $company = Company::factory()->create();
    $agent = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'agent',
        'status' => 'active',
        'designation' => CATALOG_DESIGNATION,
        'job_description' => CATALOG_JOB_DESCRIPTION,
    ]);

    $catalog = makeCapabilityCatalog();
    $descriptor = $catalog->descriptorFor($agent->id);

    expect($descriptor->displaySummary)->toBe(CATALOG_DESIGNATION.' — '.CATALOG_JOB_DESCRIPTION);
});

it('falls back to designation only when job description is empty', function (): void {
    $company = Company::factory()->create();
    $agent = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'agent',
        'status' => 'active',
        'designation' => CATALOG_DESIGNATION,
        'job_description' => null,
    ]);

    $catalog = makeCapabilityCatalog();
    $descriptor = $catalog->descriptorFor($agent->id);

    expect($descriptor->displaySummary)->toBe(CATALOG_DESIGNATION);
});

it('falls back to job description only when designation is empty', function (): void {
    $company = Company::factory()->create();
    $agent = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'agent',
        'status' => 'active',
        'designation' => null,
        'job_description' => CATALOG_JOB_DESCRIPTION,
    ]);

    $catalog = makeCapabilityCatalog();
    $descriptor = $catalog->descriptorFor($agent->id);

    expect($descriptor->displaySummary)->toBe(CATALOG_JOB_DESCRIPTION);
});

it('returns General Agent summary when both designation and job description are empty', function (): void {
    $company = Company::factory()->create();
    $agent = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'agent',
        'status' => 'active',
        'designation' => null,
        'job_description' => null,
    ]);

    $catalog = makeCapabilityCatalog();
    $descriptor = $catalog->descriptorFor($agent->id);

    expect($descriptor->displaySummary)->toBe('General Agent');
});

// --- allDescriptors ---

it('returns descriptors for all active agents including seeded system agents', function (): void {
    $company = Company::factory()->create();
    $catalog = makeCapabilityCatalog();

    $baselineCount = count($catalog->allDescriptors());

    createAgentWithCapabilities($company, ['domains' => [CATALOG_DOMAIN_IT_SUPPORT]]);
    createAgentWithCapabilities($company, ['domains' => [CATALOG_DOMAIN_CODE_REVIEW]]);

    // Inactive agent should not appear
    Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'agent',
        'status' => 'inactive',
    ]);

    // Human employee should not appear
    Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'permanent',
        'status' => 'active',
    ]);

    $descriptors = $catalog->allDescriptors();

    expect($descriptors)->toHaveCount($baselineCount + 2)
        ->and($descriptors)->each->toBeInstanceOf(AgentCapabilityDescriptor::class);
});

it('does not include inactive or non-agent employees in allDescriptors', function (): void {
    $company = Company::factory()->create();
    $catalog = makeCapabilityCatalog();

    $baselineCount = count($catalog->allDescriptors());

    // These should NOT increase the count
    Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'agent',
        'status' => 'inactive',
    ]);
    Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'permanent',
        'status' => 'active',
    ]);

    expect($catalog->allDescriptors())->toHaveCount($baselineCount);
});
