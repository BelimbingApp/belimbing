<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Base\AI\Services\ModelCatalogService;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\ModelDiscoveryService;

/**
 * Model management state and actions for the provider manager component.
 *
 * Handles add model (manual), toggle availability, inline cost overrides,
 * default model selection, and per-model execution controls. For API-discovered
 * providers, models are toggled on/off rather than deleted. Providers that supply
 * an authoritative list reconcile the DB on sync and drop rows that are not on
 * that list.
 */
trait ManagesModels
{
    use ManagesExecutionControls;

    private const ZERO_COST = '0.000000';

    public bool $showModelForm = false;

    public ?int $modelProviderId = null;

    /** Model whose execution controls are open in the editor modal. */
    public ?int $editingControlsModelId = null;

    public bool $showExecutionControlsModal = false;

    /** @var array<string, mixed> */
    public array $editingExecutionControls = [];

    public string $modelModelName = '';

    /**
     * Toggle a model's availability for Agents.
     *
     * Replaces the former delete action — models are activated/deactivated
     * rather than removed, since they originate from API discovery.
     *
     * @param  int  $modelId  Model to toggle
     */
    public function toggleModelActive(int $modelId): void
    {
        $model = AiProviderModel::query()->find($modelId);

        if (! $model) {
            return;
        }

        $model->update(['is_active' => ! $model->is_active]);

        if ($model->is_active) {
            app(ModelDiscoveryService::class)->ensureDefaultModel($model->provider);
        }
    }

    /**
     * Update a single cost override field for a model (inline editing).
     *
     * @param  int  $modelId  Model to update
     * @param  string  $field  Cost dimension: input, output, cache_read, cache_write
     * @param  string|null  $value  New cost value (null or empty clears the override)
     */
    public function updateModelCost(int $modelId, string $field, ?string $value): void
    {
        $allowed = ['input', 'output', 'cache_read', 'cache_write'];

        if (! in_array($field, $allowed, true)) {
            return;
        }

        $model = AiProviderModel::query()->find($modelId);

        if (! $model) {
            return;
        }

        $cost = $model->cost_override ?? [];
        $cost[$field] = ($value !== null && $value !== '') ? $value : null;

        $hasAnyCost = array_filter($cost, fn ($v) => $v !== null && $v !== '') !== [];

        $model->update(['cost_override' => $hasAnyCost ? $cost : null]);
    }

    public function openCreateModel(int $providerId): void
    {
        $this->resetModelForm();
        $this->modelProviderId = $providerId;
        $this->showModelForm = true;
    }

    public function saveModel(): void
    {
        if ($this->modelProviderId === null) {
            return;
        }

        $this->validate([
            'modelModelName' => ['required', 'string', 'max:255'],
        ]);

        AiProviderModel::query()->updateOrCreate(
            [
                'ai_provider_id' => $this->modelProviderId,
                'model_id' => $this->modelModelName,
            ],
            ['is_active' => true],
        );

        $this->showModelForm = false;
        $this->resetModelForm();
    }

    /**
     * Set a model as the default for its provider.
     */
    public function setDefaultModel(int $modelId): void
    {
        $model = AiProviderModel::query()->find($modelId);

        if (! $model) {
            return;
        }

        $model->setAsDefault();
    }

    private function resetModelForm(): void
    {
        $this->modelProviderId = null;
        $this->modelModelName = '';
        $this->resetValidation();
    }

    /**
     * Open the per-model execution-controls editor.
     */
    public function openModelExecutionControls(int $modelId): void
    {
        $model = AiProviderModel::query()->with('provider')->find($modelId);

        if ($model === null) {
            return;
        }

        $this->editingControlsModelId = $modelId;
        $this->editingExecutionControls = $this->hydrateExecutionControlsConfig(
            is_array($model->execution_controls) ? $model->execution_controls : null,
        );
        $this->showExecutionControlsModal = true;
    }

    /**
     * Close the per-model execution-controls editor without saving (autosave already persisted).
     */
    public function closeModelExecutionControls(): void
    {
        $this->editingControlsModelId = null;
        $this->editingExecutionControls = [];
        $this->showExecutionControlsModal = false;
    }

    /**
     * Livewire hook: when the modal is dismissed via Alpine (esc/backdrop), reset state.
     */
    public function updatedShowExecutionControlsModal(bool $value): void
    {
        if (! $value) {
            $this->editingControlsModelId = null;
            $this->editingExecutionControls = [];
        }
    }

    /**
     * Reset the editing model's controls back to system defaults (clears the override row).
     */
    public function clearModelExecutionControls(): void
    {
        if ($this->editingControlsModelId === null) {
            return;
        }

        $model = AiProviderModel::query()->find($this->editingControlsModelId);
        $model?->update(['execution_controls' => null]);

        $this->editingExecutionControls = $this->hydrateExecutionControlsConfig(null);
    }

    /**
     * Autosave on any control field change.
     */
    public function updatedEditingExecutionControls(mixed $value = null, ?string $path = null): void
    {
        if ($this->editingControlsModelId === null) {
            return;
        }

        $model = AiProviderModel::query()->find($this->editingControlsModelId);

        if ($model === null) {
            return;
        }

        $normalized = $this->normalizeExecutionControlsConfig($this->editingExecutionControls);
        $hasOverrides = array_filter(
            $normalized,
            static fn ($v) => $v !== null && $v !== '' && $v !== [],
        ) !== [];

        $model->update(['execution_controls' => $hasOverrides ? $normalized : null]);
    }

    /**
     * Resolve the schema for the model currently being edited.
     *
     * @return array<string, mixed>|null
     */
    protected function editingModelExecutionControlSchema(): ?array
    {
        if ($this->editingControlsModelId === null) {
            return null;
        }

        $model = AiProviderModel::query()->with('provider')->find($this->editingControlsModelId);

        if ($model === null || $model->provider === null) {
            return null;
        }

        $apiType = app(ModelCatalogService::class)->resolveApiType($model->provider->name, $model->model_id);

        return $this->executionControlSchema(
            providerName: $model->provider->name,
            model: $model->model_id,
            apiType: $apiType,
            config: $this->editingExecutionControls,
        );
    }
}
