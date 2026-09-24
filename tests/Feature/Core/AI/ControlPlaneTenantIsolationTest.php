<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\AI\Enums\AiRunStatus;
use App\Core\AI\Livewire\ControlPlane;
use App\Core\AI\Models\AiProvider;
use App\Core\AI\Models\AiRun;
use App\Core\AI\Services\ControlPlane\RunDiagnosticService;
use App\Core\Company\Models\Company;
use App\Core\Employee\Models\Employee;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

const TENANT_CONTROL_PLANE_OWN_RUN_ID = 'run_tenant_owned_000001';
const TENANT_CONTROL_PLANE_FOREIGN_RUN_ID = 'run_tenant_foreign_0001';

beforeEach(function (): void {
    $this->originalStoragePath = app()->storagePath();
    $this->testingStoragePath = base_path(
        'storage/framework/testing/control-plane-tenant-'.bin2hex(random_bytes(4)),
    );

    File::ensureDirectoryExists($this->testingStoragePath);
    app()->useStoragePath($this->testingStoragePath);
    config()->set('ai.workspace_path', $this->testingStoragePath.'/ai/workspace');
});

afterEach(function (): void {
    if (isset($this->originalStoragePath) && is_string($this->originalStoragePath)) {
        app()->useStoragePath($this->originalStoragePath);
    }

    if (isset($this->testingStoragePath) && is_string($this->testingStoragePath)) {
        File::deleteDirectory($this->testingStoragePath);
    }
});

/**
 * @return array{Employee, AiRun}
 */
function createTenantControlPlaneRun(Company $company, string $runId, string $status): array
{
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'employee_type' => 'agent',
    ]);

    $run = AiRun::query()->create([
        'id' => $runId,
        'employee_id' => $employee->id,
        'session_id' => 'sess_'.$runId,
        'source' => 'chat',
        'execution_mode' => 'interactive',
        'status' => $status,
        'started_at' => now(),
    ]);

    return [$employee, $run];
}

it('derives immutable run tenancy from the employee company', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'AI Run Owner']);
    [, $run] = createTenantControlPlaneRun($company, TENANT_CONTROL_PLANE_OWN_RUN_ID, AiRunStatus::Queued->value);

    expect($run->tenant_id)->toBe($tenant->id);

    $run->tenant_id = $tenant->id + 1;

    expect(fn () => $run->save())
        ->toThrow(LogicException::class, 'tenant assignment is immutable');
});

it('scopes control plane runs, health, agents, and providers to the active tenant', function (): void {
    $user = createAdminUser();
    $company = $user->company;
    [, $foreignCompany] = createTenantWithCompany(['name' => 'Foreign AI Tenant']);

    [$ownAgent, $ownRun] = createTenantControlPlaneRun(
        $company,
        TENANT_CONTROL_PLANE_OWN_RUN_ID,
        AiRunStatus::Queued->value,
    );
    [$foreignAgent, $foreignRun] = createTenantControlPlaneRun(
        $foreignCompany,
        TENANT_CONTROL_PLANE_FOREIGN_RUN_ID,
        AiRunStatus::Running->value,
    );

    AiProvider::query()->create([
        'company_id' => $company->id,
        'name' => 'tenant-owned-provider',
        'family' => AiProvider::FAMILY_LLM,
        'display_name' => 'Tenant-owned provider',
        'base_url' => 'https://own-provider.invalid',
        'auth_type' => 'api_key',
        'is_active' => true,
    ]);
    AiProvider::query()->create([
        'company_id' => $foreignCompany->id,
        'name' => 'foreign-provider',
        'family' => AiProvider::FAMILY_LLM,
        'display_name' => 'Foreign provider',
        'base_url' => 'https://foreign-provider.invalid',
        'auth_type' => 'api_key',
        'is_active' => true,
    ]);

    $component = Livewire::actingAs($user)->test(ControlPlane::class);
    $agentIds = array_column($component->get('agentOptions'), 'id');
    $providerIds = array_column($component->get('providerSnapshots'), 'target_id');

    expect($agentIds)
        ->toContain($ownAgent->id)
        ->not->toContain($foreignAgent->id)
        ->and($providerIds)
        ->toContain('tenant-owned-provider')
        ->not->toContain('foreign-provider')
        ->and($component->get('runHealthCounts.queued'))->toBe(1)
        ->and($component->get('runHealthCounts.running'))->toBe(0);

    $component
        ->set('inspectRunId', $foreignRun->id)
        ->call('inspectRun')
        ->assertSet('inspectionError', 'Run not found.')
        ->set('inspectRunId', $ownRun->id)
        ->call('inspectRun')
        ->assertSet('inspectionError', '');

    $recentRunIds = collect(app(RunDiagnosticService::class)->recentRuns())
        ->pluck('run_id');

    expect($recentRunIds)
        ->toContain($ownRun->id)
        ->not->toContain($foreignRun->id);
});

it('rejects foreign run detail and wire-log reads before serving files', function (): void {
    $this->withoutVite();

    $user = createAdminUser();
    [, $ownRun] = createTenantControlPlaneRun(
        $user->company,
        TENANT_CONTROL_PLANE_OWN_RUN_ID,
        AiRunStatus::Succeeded->value,
    );
    [, $foreignCompany] = createTenantWithCompany(['name' => 'Foreign Wire Log Tenant']);
    [, $foreignRun] = createTenantControlPlaneRun(
        $foreignCompany,
        TENANT_CONTROL_PLANE_FOREIGN_RUN_ID,
        AiRunStatus::Succeeded->value,
    );

    $wireLogDirectory = storage_path('app/ai/wire-logs');
    File::ensureDirectoryExists($wireLogDirectory);
    File::put($wireLogDirectory.'/'.$ownRun->id.'.jsonl', "{\"tenant\":\"own\"}\n");
    File::put($wireLogDirectory.'/'.$foreignRun->id.'.jsonl', "{\"tenant\":\"foreign\"}\n");

    $this->actingAs($user)
        ->get(route('admin.ai.runs.show', ['runId' => $foreignRun->id]))
        ->assertNotFound();
    $this->actingAs($user)
        ->get(route('admin.ai.runs.wire-log-entry', [
            'runId' => $foreignRun->id,
            'entryNumber' => 1,
        ]))
        ->assertNotFound();

    $ownWireLogResponse = $this->actingAs($user)
        ->get(route('admin.ai.runs.wire-log-entry', [
            'runId' => $ownRun->id,
            'entryNumber' => 1,
        ]))
        ->assertOk();

    expect($ownWireLogResponse->streamedContent())->toContain('"tenant":"own"');

    app(TenantContext::class)->set((int) $user->tenant_id);

    expect(app(RunDiagnosticService::class)->wireLogDiskUsageBytes())
        ->toBe(File::size($wireLogDirectory.'/'.$ownRun->id.'.jsonl'));
});

it('rejects malformed persisted session ids before diagnostic transcript lookup', function (): void {
    $user = createAdminUser();
    [$employee, $run] = createTenantControlPlaneRun(
        $user->company,
        TENANT_CONTROL_PLANE_OWN_RUN_ID,
        AiRunStatus::Succeeded->value,
    );
    $run->session_id = '../../../diagnostic-secret';
    $run->save();

    $workspace = (string) config('ai.workspace_path');
    File::ensureDirectoryExists($workspace.'/'.$employee->id.'/sessions');
    $escapedTranscript = dirname($workspace).'/diagnostic-secret.jsonl';
    File::ensureDirectoryExists(dirname($escapedTranscript));
    File::put($escapedTranscript, implode("\n", [
        json_encode([
            'role' => 'user',
            'content' => 'outside-tenant-prompt-secret',
            'timestamp' => '2026-01-01T00:00:00+00:00',
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'role' => 'assistant',
            'content' => 'outside-tenant-transcript-secret',
            'timestamp' => '2026-01-01T00:00:01+00:00',
            'run_id' => $run->id,
        ], JSON_THROW_ON_ERROR),
    ])."\n");

    app(TenantContext::class)->set((int) $user->tenant_id);
    $diagnostics = app(RunDiagnosticService::class);

    expect($diagnostics->runTranscript($run))->toBe([])
        ->and($diagnostics->triggeringPrompt($run))->toBeNull();
});
