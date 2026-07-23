<?php

use App\Base\AI\Services\AiRuntimeSettings;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Support\ExecutableLocator;
use App\Modules\Core\AI\Livewire\ControlPlane;
use App\Modules\Core\AI\Livewire\Tools\Workspace;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

const AI_RUNTIME_SETTINGS_LICENSEE = 'AI Runtime Settings Licensee';
const AI_RUNTIME_SETTINGS_PDFTOTEXT_PATH = 'C:\\Runtime Settings\\pdftotext.exe';

beforeEach(function (): void {
    Company::provisionLicensee(AI_RUNTIME_SETTINGS_LICENSEE);
    Employee::provisionLara();
});

it('stores and restores the global tool-loop guardrail from the control plane', function (): void {
    $user = createAdminUser();
    $settings = app(SettingsService::class);
    config([AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY => 24]);

    Livewire::actingAs($user)
        ->withQueryParams(['tab' => 'runtime'])
        ->test(ControlPlane::class)
        ->assertSet('activeTab', 'runtime')
        ->assertSet('maxToolRounds', '100')
        ->assertViewHas(
            'maxToolRoundsDefinition',
            fn ($definition): bool => $definition->default === 100
                && $definition->ruleParameter('min') === '1'
                && $definition->ruleParameter('max') === '500',
        )
        ->assertSee('Maximum tool rounds per turn')
        ->set('maxToolRounds', '160')
        ->call('saveRuntimeGuardrails')
        ->assertHasNoErrors();

    expect($settings->get(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBe(160);

    Livewire::actingAs($user)
        ->withQueryParams(['tab' => 'runtime'])
        ->test(ControlPlane::class)
        ->call('restoreRuntimeGuardrailDefaults')
        ->assertSet('maxToolRounds', '100');

    expect($settings->has(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY))->toBeFalse();
});

it('migrates the legacy iteration row to the canonical round setting', function (): void {
    DB::table('base_settings')->insert([
        'key' => 'ai.llm.agentic.max_tool_iterations',
        'value' => json_encode(64, JSON_THROW_ON_ERROR),
        'is_encrypted' => false,
        'scope_type' => null,
        'scope_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = require app_path(
        'Modules/Core/AI/Database/Migrations/0200_02_01_000017_rename_max_tool_iterations_setting.php',
    );
    $migration->up();

    expect(app(AiRuntimeSettings::class)->maxToolRounds())->toBe(64)
        ->and(DB::table('base_settings')
            ->where('key', 'ai.llm.agentic.max_tool_iterations')
            ->exists())->toBeFalse();
});

it('rejects invalid or unauthorized runtime guardrail writes', function (): void {
    $admin = createAdminUser();

    Livewire::actingAs($admin)
        ->test(ControlPlane::class)
        ->set('maxToolRounds', '501')
        ->call('saveRuntimeGuardrails')
        ->assertHasErrors(['maxToolRounds' => 'max']);

    expect(fn () => Livewire::actingAs(createTenantOwnerUser())
        ->test(ControlPlane::class)
        ->set('maxToolRounds', '80')
        ->call('saveRuntimeGuardrails'))
        ->toThrow(AuthorizationDeniedException::class);
});

it('configures and verifies pdftotext from the document extraction workspace', function (): void {
    $user = createAdminUser();
    config([AiRuntimeSettings::PDFTOTEXT_PATH_KEY => 'C:\\Environment Fallback\\pdftotext.exe']);
    $locator = Mockery::mock(ExecutableLocator::class);
    $locator->shouldReceive('find')
        ->andReturnUsing(fn (array $candidates): ?string => in_array(
            AI_RUNTIME_SETTINGS_PDFTOTEXT_PATH,
            $candidates,
            true,
        ) ? AI_RUNTIME_SETTINGS_PDFTOTEXT_PATH : null);
    app()->instance(ExecutableLocator::class, $locator);

    Livewire::actingAs($user)
        ->test(Workspace::class, ['toolName' => 'document_analysis'])
        ->assertSee('pdftotext executable')
        ->assertDontSee('Use the "Try It" panel')
        ->assertSet('configValues.ai.tools.document_analysis.pdftotext_path', '')
        ->assertSet('documentExtractorCheck.success', false)
        ->set(
            'configValues.ai.tools.document_analysis.pdftotext_path',
            AI_RUNTIME_SETTINGS_PDFTOTEXT_PATH,
        )
        ->call('saveConfig')
        ->assertHasNoErrors()
        ->assertSet('documentExtractorCheck.success', true)
        ->assertSee('Extractor Available')
        ->assertSee('pdftotext is available to the document extraction tool.');

    $settings = app(SettingsService::class);

    expect($settings->get(AiRuntimeSettings::PDFTOTEXT_PATH_KEY))
        ->toBe(AI_RUNTIME_SETTINGS_PDFTOTEXT_PATH)
        ->and($settings->get('ai.tools.document_analysis.last_verified_success'))
        ->toBeTrue();
});

it('restores tool workspace overrides by deleting their setting rows', function (): void {
    $user = createAdminUser();
    $settings = app(SettingsService::class);
    $settings->set(AiRuntimeSettings::WEB_FETCH_TIMEOUT_KEY, 90);
    $settings->set(AiRuntimeSettings::WEB_FETCH_MAX_BYTES_KEY, 10_000);

    Livewire::actingAs($user)
        ->test(Workspace::class, ['toolName' => 'web_fetch'])
        ->assertSet('configValues.'.AiRuntimeSettings::WEB_FETCH_TIMEOUT_KEY, 90)
        ->assertSet('configValues.'.AiRuntimeSettings::WEB_FETCH_MAX_BYTES_KEY, 10_000)
        ->call('restoreConfig')
        ->assertHasNoErrors()
        ->assertSet('configValues.'.AiRuntimeSettings::WEB_FETCH_TIMEOUT_KEY, 30)
        ->assertSet('configValues.'.AiRuntimeSettings::WEB_FETCH_MAX_BYTES_KEY, 5_242_880);

    expect($settings->has(AiRuntimeSettings::WEB_FETCH_TIMEOUT_KEY))->toBeFalse()
        ->and($settings->has(AiRuntimeSettings::WEB_FETCH_MAX_BYTES_KEY))->toBeFalse();
});
