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

    public ?int $backupProviderId = null;

    public ?string $backupModelId = null;

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
        return $this->modelsForProvider($this->selectedProviderId);
    }

    protected function availableBackupModels(): Collection
    {
        return $this->modelsForProvider($this->backupProviderId);
    }

    private function modelsForProvider(?int $providerId): Collection
    {
        if ($providerId === null) {
            return collect();
        }

        return AiProviderModel::query()
            ->where('ai_provider_id', $providerId)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('model_id')
            ->get(['id', 'model_id', 'is_default']);
    }

    /**
     * @return array{isUsingDefault: bool, activeProviderName: ?string, activeModelId: ?string, activeBackupProviderName: ?string, activeBackupModelId: ?string}
     */
    protected function resolveActiveSelection(ConfigResolver $resolver, int $employeeId): array
    {
        $workspaceConfig = $resolver->readWorkspaceConfig($employeeId);
        $models = $workspaceConfig['llm']['models'] ?? [];
        $hasExplicitConfig = $workspaceConfig !== null && $models !== [];

        if ($hasExplicitConfig) {
            $primary = $models[0];
            $backup = $models[1] ?? null;

            return [
                'isUsingDefault' => false,
                'activeProviderName' => $primary['provider'] ?? null,
                'activeModelId' => $primary['model'] ?? null,
                'activeBackupProviderName' => $backup['provider'] ?? null,
                'activeBackupModelId' => $backup['model'] ?? null,
            ];
        }

        $default = $resolver->resolveDefault(Company::LICENSEE_ID);

        return [
            'isUsingDefault' => true,
            'activeProviderName' => $default['provider_name'] ?? null,
            'activeModelId' => $default['model'] ?? null,
            'activeBackupProviderName' => null,
            'activeBackupModelId' => null,
        ];
    }

    protected function hydrateFromCurrentConfig(ConfigResolver $resolver, int $employeeId): void
    {
        $workspaceConfig = $resolver->readWorkspaceConfig($employeeId);
        $models = $workspaceConfig['llm']['models'] ?? [];
        $primaryEntry = $models[0] ?? null;
        $backupEntry = $models[1] ?? null;

        if ($primaryEntry !== null) {
            $this->selectedProviderId = $this->providerIdForName($primaryEntry['provider'] ?? null);
            $this->selectedModelId = $primaryEntry['model'] ?? null;

            if ($backupEntry !== null) {
                $this->backupProviderId = $this->providerIdForName($backupEntry['provider'] ?? null);
                $this->backupModelId = $backupEntry['model'] ?? null;
            }

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

    /**
     * Align the backup model with the backup provider.
     *
     * Clears the backup model when the provider changes or the model is no longer valid.
     *
     * @param  bool  $forceDefault  When true, pick the provider default even if the current model is still valid.
     */
    protected function hydrateBackupModel(bool $forceDefault = false): void
    {
        if ($this->backupProviderId === null) {
            $this->backupModelId = null;

            return;
        }

        $providerExists = AiProvider::query()
            ->whereKey($this->backupProviderId)
            ->forCompany(Company::LICENSEE_ID)
            ->active()
            ->exists();

        if (! $providerExists) {
            $this->backupProviderId = null;
            $this->backupModelId = null;

            return;
        }

        if (! $forceDefault && $this->backupModelId !== null) {
            $modelStillValid = AiProviderModel::query()
                ->where('ai_provider_id', $this->backupProviderId)
                ->where('model_id', $this->backupModelId)
                ->active()
                ->exists();

            if ($modelStillValid) {
                return;
            }
        }

        $this->backupModelId = AiProviderModel::query()
            ->where('ai_provider_id', $this->backupProviderId)
            ->active()
            ->orderByDesc('is_default')
            ->orderBy('model_id')
            ->value('model_id');
    }

    /**
     * Clear the backup model configuration.
     */
    protected function clearBackup(): void
    {
        $this->backupProviderId = null;
        $this->backupModelId = null;
    }

    protected function validateProviderAndModel(): void
    {
        $rules = [
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
        ];

        if ($this->backupProviderId !== null) {
            $rules['backupProviderId'] = [
                'required',
                'integer',
                Rule::exists('ai_providers', 'id')
                    ->where('company_id', Company::LICENSEE_ID)
                    ->where('is_active', true),
            ];
            $rules['backupModelId'] = [
                'required',
                'string',
                Rule::exists('ai_provider_models', 'model_id')
                    ->where('ai_provider_id', $this->backupProviderId)
                    ->where('is_active', true),
            ];
        }

        $this->validate($rules);
    }

    protected function writeConfig(int $employeeId): void
    {
        $provider = AiProvider::query()
            ->whereKey($this->selectedProviderId)
            ->forCompany(Company::LICENSEE_ID)
            ->active()
            ->firstOrFail();

        $resolver = app(ConfigResolver::class);
        $workspaceConfig = $resolver->readWorkspaceConfig($employeeId) ?? [];
        $llm = is_array($workspaceConfig['llm'] ?? null) ? $workspaceConfig['llm'] : [];
        $existingModels = is_array($llm['models'] ?? null) ? array_values($llm['models']) : [];

        $models = [
            $this->buildModelConfigEntry(
                providerName: $provider->name,
                modelId: $this->selectedModelId,
                existingEntry: is_array($existingModels[0] ?? null) ? $existingModels[0] : null,
            ),
        ];

        if ($this->backupProviderId !== null && $this->backupModelId !== null) {
            $backupProvider = AiProvider::query()
                ->whereKey($this->backupProviderId)
                ->forCompany(Company::LICENSEE_ID)
                ->active()
                ->firstOrFail();

            $models[] = $this->buildModelConfigEntry(
                providerName: $backupProvider->name,
                modelId: $this->backupModelId,
                existingEntry: is_array($existingModels[1] ?? null) ? $existingModels[1] : null,
            );
        }

        $llm['models'] = $models;
        $workspaceConfig['llm'] = $llm;

        $resolver->writeWorkspaceConfig($employeeId, $workspaceConfig);
    }

    /**
     * Preserve canonical runtime overrides while the Lara setup page edits model identity.
     *
     * @param  array<string, mixed>|null  $existingEntry
     * @return array<string, mixed>
     */
    private function buildModelConfigEntry(string $providerName, ?string $modelId, ?array $existingEntry): array
    {
        $entry = [
            'provider' => $providerName,
            'model' => $modelId,
        ];

        $executionControls = $existingEntry['execution_controls'] ?? null;
        if (is_array($executionControls)) {
            $entry['execution_controls'] = $executionControls;
        }

        $timeout = $existingEntry['timeout'] ?? null;
        if (is_int($timeout) || is_numeric($timeout)) {
            $entry['timeout'] = (int) $timeout;
        }

        return $entry;
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
