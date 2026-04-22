<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\ModelDiscoveryService;

/**
 * Model management state and actions for the provider manager component.
 *
 * Handles add model (manual), toggle availability, inline cost overrides,
 * and default model selection. For API-discovered providers, models are
 * toggled on/off rather than deleted. Curated providers (e.g. OpenAI Codex)
 * reconcile the DB on sync and drop rows that are not on the curated list.
 */
trait ManagesModels
{
    private const ZERO_COST = '0.000000';

    public bool $showModelForm = false;

    public ?int $modelProviderId = null;

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
}
