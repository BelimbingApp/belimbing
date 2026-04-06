<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Setup;

use App\Modules\Core\AI\Livewire\Concerns\HandlesProviderDiagnostics;
use App\Modules\Core\AI\Livewire\Concerns\ManagesAgentModelSelection;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Kodi extends Component
{
    use HandlesProviderDiagnostics;
    use ManagesAgentModelSelection;

    public function mount(): void
    {
        if (! Company::query()->whereKey(Company::LICENSEE_ID)->exists()) {
            return;
        }

        if (Employee::laraActivationState() !== true) {
            return;
        }

        $resolver = app(ConfigResolver::class);
        $this->hydrateFromCurrentConfig($resolver, Employee::KODI_ID);

        if ($this->selectedProviderId === null) {
            $this->selectedProviderId = $this->defaultProviderId();
        }

        $this->hydrateSelectedModel(forceDefault: false);
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
     * Auto-save when model selection changes (Kodi is always configurable once Lara is active).
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
        $this->writeConfig(Employee::KODI_ID);

        $message = match ($changed) {
            'backup' => $this->backupModelId !== null
                ? __('Backup saved: :model', ['model' => $this->backupModelId])
                : __('Backup cleared.'),
            default => __('Primary saved: :model', ['model' => $this->selectedModelId]),
        };

        $this->dispatch($changed === 'backup' ? 'backup-saved' : 'primary-saved', message: $message);
    }

    public function render(): View
    {
        $licenseeExists = Company::query()->whereKey(Company::LICENSEE_ID)->exists();
        $laraActivated = Employee::laraActivationState() === true;

        $providers = collect();
        $models = collect();
        $backupModels = collect();
        $activeSelection = [
            'isUsingDefault' => false,
            'activeProviderName' => null,
            'activeModelId' => null,
            'activeBackupProviderName' => null,
            'activeBackupModelId' => null,
        ];

        if ($licenseeExists && $laraActivated) {
            $providers = $this->availableProviders();
        }

        if ($laraActivated && $this->selectedProviderId) {
            $models = $this->availableModels();
        }

        if ($laraActivated && $this->backupProviderId) {
            $backupModels = $this->availableBackupModels();
        }

        if ($laraActivated) {
            $activeSelection = $this->resolveActiveSelection(app(ConfigResolver::class), Employee::KODI_ID);
        }

        return view('livewire.admin.setup.kodi', [
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
        ]);
    }
}
