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

class Lara extends Component
{
    public ?int $selectedProviderId = null;

    public ?string $selectedModelId = null;

    public function mount(): void
    {
        if (! Company::query()->whereKey(Company::LICENSEE_ID)->exists()) {
            return;
        }

        // Already activated — nothing to set up.
        if (Employee::laraActivationState() === true) {
            $this->redirect(route('admin.ai.playground'), navigate: true);

            return;
        }

        $this->selectedProviderId = AiProvider::query()
            ->forCompany(Company::LICENSEE_ID)
            ->active()
            ->orderBy('priority')
            ->orderBy('display_name')
            ->value('id');

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
        $this->hydrateSelectedModel();
    }

    /**
     * Activate Lara by writing workspace config with selected provider and model.
     */
    public function activateLara(): void
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
        $resolver->writeWorkspaceConfig(Employee::LARA_ID, $config);

        Session::flash('success', __('Lara has been activated.'));
        $this->redirect(route('admin.ai.playground'), navigate: true);
    }

    /**
     * Provide data to the Blade template.
     */
    public function render(): \Illuminate\Contracts\View\View
    {
        $activationState = Employee::laraActivationState();
        $licenseeExists = Company::query()->whereKey(Company::LICENSEE_ID)->exists();

        $providers = collect();
        $models = collect();

        if ($licenseeExists) {
            $providers = AiProvider::query()
                ->forCompany(Company::LICENSEE_ID)
                ->active()
                ->orderBy('display_name')
                ->get(['id', 'display_name', 'name']);
        }

        if ($this->selectedProviderId) {
            $models = AiProviderModel::query()
                ->where('ai_provider_id', $this->selectedProviderId)
                ->active()
                ->orderBy('model_id')
                ->get(['id', 'model_id']);
        }

        return view('livewire.admin.setup.lara', [
            'laraExists' => $activationState !== null,
            'licenseeExists' => $licenseeExists,
            'laraActivated' => $activationState === true,
            'providers' => $providers,
            'models' => $models,
        ]);
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
}
