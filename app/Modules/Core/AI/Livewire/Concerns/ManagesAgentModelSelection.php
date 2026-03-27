<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

trait ManagesAgentModelSelection
{
    public ?int $selectedProviderId = null;

    public ?string $selectedModelId = null;

    protected function defaultProviderId(): ?int
    {
        return AiProvider::query()
            ->forCompany(Company::LICENSEE_ID)
            ->active()
            ->orderBy('priority')
            ->orderBy('display_name')
            ->value('id');
    }

    protected function availableProviders(): Collection
    {
        return AiProvider::query()
            ->forCompany(Company::LICENSEE_ID)
            ->active()
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'name']);
    }

    protected function availableModels(): Collection
    {
        if ($this->selectedProviderId === null) {
            return collect();
        }

        return AiProviderModel::query()
            ->where('ai_provider_id', $this->selectedProviderId)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('model_id')
            ->get(['id', 'model_id', 'is_default']);
    }

    /**
     * @return array{isUsingDefault: bool, activeProviderName: ?string, activeModelId: ?string}
     */
    protected function resolveActiveSelection(ConfigResolver $resolver, int $employeeId): array
    {
        $workspaceConfig = $resolver->readWorkspaceConfig($employeeId);
        $hasExplicitConfig = $workspaceConfig !== null
            && ($workspaceConfig['llm']['models'] ?? []) !== [];

        if ($hasExplicitConfig) {
            $entry = $workspaceConfig['llm']['models'][0];

            return [
                'isUsingDefault' => false,
                'activeProviderName' => $entry['provider'] ?? null,
                'activeModelId' => $entry['model'] ?? null,
            ];
        }

        $default = $resolver->resolveDefault(Company::LICENSEE_ID);

        return [
            'isUsingDefault' => true,
            'activeProviderName' => $default['provider_name'] ?? null,
            'activeModelId' => $default['model'] ?? null,
        ];
    }

    protected function hydrateFromCurrentConfig(ConfigResolver $resolver, int $employeeId): void
    {
        $workspaceConfig = $resolver->readWorkspaceConfig($employeeId);
        $modelEntry = $workspaceConfig['llm']['models'][0] ?? null;

        if ($modelEntry !== null) {
            $this->selectedProviderId = $this->providerIdForName($modelEntry['provider'] ?? null);
            $this->selectedModelId = $modelEntry['model'] ?? null;

            return;
        }

        $default = $resolver->resolveDefault(Company::LICENSEE_ID);

        if ($default === null) {
            return;
        }

        $this->selectedProviderId = $this->providerIdForName($default['provider_name'] ?? null);
        $this->selectedModelId = $default['model'] ?? null;
    }

    /**
     * Align the selected model with the current provider.
     *
     * @param  bool  $forceDefault  When true, pick the provider default even if the current model is still valid.
     */
    protected function hydrateSelectedModel(bool $forceDefault = false): void
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

        if (! $forceDefault && $this->selectedModelId !== null) {
            $modelStillValid = AiProviderModel::query()
                ->where('ai_provider_id', $this->selectedProviderId)
                ->where('model_id', $this->selectedModelId)
                ->active()
                ->exists();

            if ($modelStillValid) {
                return;
            }
        }

        $this->selectedModelId = AiProviderModel::query()
            ->where('ai_provider_id', $this->selectedProviderId)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('model_id')
            ->value('model_id');
    }

    protected function validateProviderAndModel(): void
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

    protected function writeConfig(int $employeeId): void
    {
        $provider = AiProvider::query()
            ->whereKey($this->selectedProviderId)
            ->forCompany(Company::LICENSEE_ID)
            ->active()
            ->firstOrFail();

        app(ConfigResolver::class)->writeWorkspaceConfig($employeeId, [
            'llm' => [
                'models' => [
                    [
                        'provider' => $provider->name,
                        'model' => $this->selectedModelId,
                    ],
                ],
            ],
        ]);
    }

    private function providerIdForName(?string $providerName): ?int
    {
        if ($providerName === null) {
            return null;
        }

        return AiProvider::query()
            ->forCompany(Company::LICENSEE_ID)
            ->where('name', $providerName)
            ->value('id');
    }
}
