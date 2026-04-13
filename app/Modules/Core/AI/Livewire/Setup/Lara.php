<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Setup;

use App\Modules\Core\AI\Livewire\Concerns\HandlesProviderDiagnostics;
use App\Modules\Core\AI\Livewire\Concerns\ManagesAgentModelSelection;
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

    public function mount(): void
    {
        if (! Company::query()->whereKey(Company::LICENSEE_ID)->exists()) {
            return;
        }

        $resolver = app(ConfigResolver::class);

        if (Employee::laraActivationState() === true) {
            $this->hydrateFromCurrentConfig($resolver, Employee::LARA_ID);

            return;
        }

        $this->selectedProviderId = $this->defaultProviderId();

        $this->hydrateSelectedModel();
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
        ]);
    }
}
