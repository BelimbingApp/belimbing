<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Setup;

use App\Modules\Core\AI\Livewire\Concerns\HandlesProviderDiagnostics;
use App\Modules\Core\AI\Livewire\Concerns\ManagesAgentModelSelection;
use App\Modules\Core\AI\Livewire\Concerns\ManagesExecutionControls;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\LaraTaskRegistry;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class Lara extends Component
{
    use HandlesProviderDiagnostics;
    use ManagesAgentModelSelection;
    use ManagesExecutionControls;

    /** @var array<string, mixed> */
    public array $primaryExecutionControls = [];

    /** @var array<string, mixed> */
    public array $backupExecutionControls = [];

    public function mount(): void
    {
        if (! Company::query()->whereKey(Company::LICENSEE_ID)->exists()) {
            return;
        }

        $resolver = app(ConfigResolver::class);

        if (Employee::laraActivationState() === true) {
            $this->hydrateFromCurrentConfig($resolver, Employee::LARA_ID);
            $this->hydrateExecutionControlState($resolver);

            return;
        }

        $this->selectedProviderId = $this->defaultProviderId();

        $this->hydrateSelectedModel();
        $this->hydrateExecutionControlState($resolver);
    }

    /**
     * Provision the Lara employee record.
     *
     * Delegates to Employee::provisionLara() — the single source of truth.
     */
    public function provisionLara(): void
    {
        if (Employee::provisionLara()) {
            Session::flash('success', __('Lara has been provisioned.'));
        }
    }

    /**
     * Keep model selection in sync when provider selection changes.
     */
    public function updatedSelectedProviderId(): void
    {
        $this->clearProviderTestResult();
        $this->hydrateSelectedModel(forceDefault: true);
    }

    /**
     * Auto-save when model selection changes and Lara is already activated.
     */
    public function updatedSelectedModelId(): void
    {
        $this->clearProviderTestResult();
        $this->autoSaveIfActivated('primary');
    }

    /**
     * Auto-save when primary execution controls change.
     */
    public function updatedPrimaryExecutionControls(mixed $value = null, ?string $path = null): void
    {
        $this->autoSaveIfActivated('primary');
    }

    /**
     * Keep backup model selection in sync when backup provider changes.
     */
    public function updatedBackupProviderId(): void
    {
        $this->clearBackupProviderTestResult();
        $this->hydrateBackupModel(forceDefault: true);
        $this->autoSaveIfActivated('backup');
    }

    /**
     * Auto-save when backup model selection changes.
     */
    public function updatedBackupModelId(): void
    {
        $this->clearBackupProviderTestResult();
        $this->autoSaveIfActivated('backup');
    }

    /**
     * Auto-save when backup execution controls change.
     */
    public function updatedBackupExecutionControls(mixed $value = null, ?string $path = null): void
    {
        if ($this->backupProviderId === null || $this->backupModelId === null) {
            return;
        }

        $this->autoSaveIfActivated('backup');
    }

    /**
     * Clear the backup model and auto-save.
     */
    public function removeBackup(): void
    {
        $this->clearBackup();
        $this->clearBackupProviderTestResult();
        $this->autoSaveIfActivated('backup');
    }

    private function autoSaveIfActivated(string $changed = 'primary'): void
    {
        if (Employee::laraActivationState() !== true) {
            return;
        }

        if ($this->selectedModelId === null) {
            return;
        }

        $this->validateProviderAndModel();
        $this->writeConfig(Employee::LARA_ID);

        $message = match ($changed) {
            'backup' => $this->backupModelId !== null
                ? __('Backup saved: :model', ['model' => $this->backupModelId])
                : __('Backup cleared.'),
            default => __('Primary saved: :model', ['model' => $this->selectedModelId]),
        };

        $this->dispatch($changed === 'backup' ? 'backup-saved' : 'primary-saved', message: $message);
    }

    /**
     * Activate Lara by writing workspace config with selected provider and model.
     */
    public function activateLara(): void
    {
        $this->validateProviderAndModel();
        $this->writeConfig(Employee::LARA_ID);

        Session::flash('success', __('Lara has been activated.'));
        $this->redirect(route('admin.setup.lara'), navigate: true);
    }

    /**
     * Provide data to the Blade template.
     */
    public function render(): View
    {
        $activationState = Employee::laraActivationState();
        $licenseeExists = Company::query()->whereKey(Company::LICENSEE_ID)->exists();
        $laraActivated = $activationState === true;

        $providers = collect();
        $models = collect();
        $backupModels = collect();
        $taskSummaries = [];
        $primaryExecutionControlSchema = null;
        $backupExecutionControlSchema = null;
        $activeSelection = [
            'isUsingDefault' => false,
            'activeProviderName' => null,
            'activeModelId' => null,
            'activeBackupProviderName' => null,
            'activeBackupModelId' => null,
        ];

        if ($licenseeExists) {
            $providers = $this->availableProviders();
        }

        if ($this->selectedProviderId) {
            $models = $this->availableModels();
        }

        if ($this->backupProviderId) {
            $backupModels = $this->availableBackupModels();
        }

        if ($this->selectedProviderId !== null && $this->selectedModelId !== null) {
            $primaryExecutionControlSchema = $this->resolveExecutionControlSchemaForProvider(
                $this->selectedProviderId,
                $this->selectedModelId,
                $this->primaryExecutionControls,
            );
        }

        if ($this->backupProviderId !== null && $this->backupModelId !== null) {
            $backupExecutionControlSchema = $this->resolveExecutionControlSchemaForProvider(
                $this->backupProviderId,
                $this->backupModelId,
                $this->backupExecutionControls,
            );
        }

        if ($laraActivated) {
            $resolver = app(ConfigResolver::class);
            $activeSelection = $this->resolveActiveSelection($resolver, Employee::LARA_ID);
            $taskSummaries = collect(app(LaraTaskRegistry::class)->all())
                ->map(function ($task) use ($resolver): array {
                    $config = $resolver->readTaskConfig(Employee::LARA_ID, $task->key) ?? [];
                    $mode = $config['mode'] ?? 'recommended';
                    $provider = $config['provider'] ?? null;
                    $model = $config['model'] ?? null;

                    $summary = match (true) {
                        $mode === 'primary' => __('Uses Lara primary'),
                        is_string($provider) && is_string($model) => $provider.'/'.$model,
                        default => __('No saved selection'),
                    };

                    return [
                        'label' => $task->label,
                        'summary' => $summary,
                    ];
                })
                ->all();
        }

        return view('livewire.admin.setup.lara', [
            'laraExists' => $activationState !== null,
            'licenseeExists' => $licenseeExists,
            'laraActivated' => $laraActivated,
            'providers' => $providers,
            'models' => $models,
            'backupModels' => $backupModels,
            'isUsingDefault' => $activeSelection['isUsingDefault'],
            'activeProviderName' => $activeSelection['activeProviderName'],
            'activeModelId' => $activeSelection['activeModelId'],
            'activeBackupProviderName' => $activeSelection['activeBackupProviderName'],
            'activeBackupModelId' => $activeSelection['activeBackupModelId'],
            'taskSummaries' => $taskSummaries,
            'primaryExecutionControlSchema' => $primaryExecutionControlSchema,
            'backupExecutionControlSchema' => $backupExecutionControlSchema,
        ]);
    }

    private function hydrateExecutionControlState(ConfigResolver $resolver): void
    {
        $workspaceConfig = $resolver->readWorkspaceConfig(Employee::LARA_ID);
        $models = is_array($workspaceConfig['llm']['models'] ?? null) ? array_values($workspaceConfig['llm']['models']) : [];

        $primaryConfig = is_array($models[0]['execution_controls'] ?? null) ? $models[0]['execution_controls'] : null;
        $backupConfig = is_array($models[1]['execution_controls'] ?? null) ? $models[1]['execution_controls'] : null;

        $this->primaryExecutionControls = $this->hydrateExecutionControlsConfig($primaryConfig);
        $this->backupExecutionControls = $this->hydrateExecutionControlsConfig($backupConfig);
    }

    /**
     * @param  array<string, mixed>  $controls
     * @return array<string, mixed>|null
     */
    private function resolveExecutionControlSchemaForProvider(
        int $providerId,
        string $modelId,
        array $controls,
    ): ?array {
        $resolved = app(ConfigResolver::class)->resolveForProvider($providerId, $modelId);

        if ($resolved === null) {
            return null;
        }

        return $this->executionControlSchema(
            providerName: $resolved['provider_name'],
            model: $resolved['model'],
            apiType: $resolved['api_type'],
            config: $controls,
        );
    }
}
