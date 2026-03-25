<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Setup;

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Kodi extends Component
{
    public ?int $selectedProviderId = null;

    public ?string $selectedModelId = null;

    public function mount(): void
    {
        if (! Company::query()->whereKey(Company::LICENSEE_ID)->exists()) {
            return;
        }

        if (Employee::laraActivationState() !== true) {
            return;
        }

        $resolver = app(ConfigResolver::class);
        $this->hydrateFromCurrentConfig($resolver);

        if ($this->selectedProviderId === null) {
            $this->selectedProviderId = AiProvider::query()
                ->forCompany(Company::LICENSEE_ID)
                ->active()
                ->orderBy('priority')
                ->orderBy('display_name')
                ->value('id');
        }

        $this->hydrateSelectedModel();
    }

    /**
     * Keep model selection in sync when provider selection changes.
     */
    public function updatedSelectedProviderId(): void
    {
        $this->hydrateSelectedModel();
    }

    /**
     * Auto-save when model selection changes (Kodi is always configurable once Lara is active).
     */
    public function updatedSelectedModelId(): void
    {
        if (Employee::laraActivationState() !== true) {
            return;
        }

        if ($this->selectedModelId === null) {
            return;
        }

        $this->validateProviderAndModel();
        $this->writeConfig();

        Session::flash('success', __('Kodi has been updated.'));
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $licenseeExists = Company::query()->whereKey(Company::LICENSEE_ID)->exists();
        $laraActivated = Employee::laraActivationState() === true;

        $providers = collect();
        $models = collect();
        $isUsingDefault = false;
        $activeProviderName = null;
        $activeModelId = null;

        if ($licenseeExists && $laraActivated) {
            $providers = AiProvider::query()
                ->forCompany(Company::LICENSEE_ID)
                ->active()
                ->orderBy('display_name')
                ->get(['id', 'display_name', 'name']);
        }

        if ($laraActivated && $this->selectedProviderId) {
            $models = AiProviderModel::query()
                ->where('ai_provider_id', $this->selectedProviderId)
                ->active()
                ->orderByDesc('is_default')
                ->orderBy('model_id')
                ->get(['id', 'model_id', 'is_default']);
        }

        if ($laraActivated) {
            $resolver = app(ConfigResolver::class);
            $workspaceConfig = $resolver->readWorkspaceConfig(Employee::KODI_ID);
            $hasExplicitConfig = $workspaceConfig !== null
                && ($workspaceConfig['llm']['models'] ?? []) !== [];

            if ($hasExplicitConfig) {
                $entry = $workspaceConfig['llm']['models'][0];
                $activeProviderName = $entry['provider'] ?? null;
                $activeModelId = $entry['model'] ?? null;
            } else {
                $isUsingDefault = true;
                $default = $resolver->resolveDefault(Company::LICENSEE_ID);
                $activeProviderName = $default['provider_name'] ?? null;
                $activeModelId = $default['model'] ?? null;
            }
        }

        return view('livewire.admin.setup.kodi', [
            'licenseeExists' => $licenseeExists,
            'laraActivated' => $laraActivated,
            'providers' => $providers,
            'models' => $models,
            'isUsingDefault' => $isUsingDefault,
            'activeProviderName' => $activeProviderName,
            'activeModelId' => $activeModelId,
        ]);
    }

    /**
     * Populate provider and model selections from Kodi's current config.
     *
     * Tries explicit workspace config first, then falls back to company default.
     */
    private function hydrateFromCurrentConfig(ConfigResolver $resolver): void
    {
        $workspaceConfig = $resolver->readWorkspaceConfig(Employee::KODI_ID);
        $modelEntry = $workspaceConfig['llm']['models'][0] ?? null;

        if ($modelEntry !== null) {
            $providerName = $modelEntry['provider'] ?? null;

            if ($providerName !== null) {
                $this->selectedProviderId = AiProvider::query()
                    ->forCompany(Company::LICENSEE_ID)
                    ->where('name', $providerName)
                    ->value('id');
            }

            $this->selectedModelId = $modelEntry['model'] ?? null;

            return;
        }

        $default = $resolver->resolveDefault(Company::LICENSEE_ID);

        if ($default === null) {
            return;
        }

        if ($default['provider_name'] !== null) {
            $this->selectedProviderId = AiProvider::query()
                ->forCompany(Company::LICENSEE_ID)
                ->where('name', $default['provider_name'])
                ->value('id');
        }

        $this->selectedModelId = $default['model'] ?? null;
    }

    private function hydrateSelectedModel(): void
    {
        if ($this->selectedProviderId === null) {
            $this->selectedModelId = null;

            return;
        }

        $providerExists = AiProvider::query()
            ->whereKey($this->selectedProviderId)
            ->forCompany(Company::LICENSEE_ID)
            ->active()
            ->exists();

        if (! $providerExists) {
            $this->selectedProviderId = null;
            $this->selectedModelId = null;

            return;
        }

        $this->selectedModelId = AiProviderModel::query()
            ->where('ai_provider_id', $this->selectedProviderId)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('model_id')
            ->value('model_id');
    }

    /**
     * Validate provider and model selections.
     */
    private function validateProviderAndModel(): void
    {
        $this->validate([
            'selectedProviderId' => [
                'required',
                'integer',
                Rule::exists('ai_providers', 'id')
                    ->where('company_id', Company::LICENSEE_ID)
                    ->where('is_active', true),
            ],
            'selectedModelId' => [
                'required',
                'string',
                Rule::exists('ai_provider_models', 'model_id')
                    ->where('ai_provider_id', $this->selectedProviderId)
                    ->where('is_active', true),
            ],
        ]);
    }

    /**
     * Write workspace config with the currently selected provider and model.
     */
    private function writeConfig(): void
    {
        $provider = AiProvider::query()
            ->whereKey($this->selectedProviderId)
            ->forCompany(Company::LICENSEE_ID)
            ->active()
            ->firstOrFail();

        $config = [
            'llm' => [
                'models' => [
                    [
                        'provider' => $provider->name,
                        'model' => $this->selectedModelId,
                    ],
                ],
            ],
        ];

        $resolver = app(ConfigResolver::class);
        $resolver->writeWorkspaceConfig(Employee::KODI_ID, $config);
    }
}

