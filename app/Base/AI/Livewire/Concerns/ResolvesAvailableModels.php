<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Livewire\Concerns;

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use App\Modules\Core\AI\Services\RuntimeCredentialResolver;

/**
 * Shared model-loading and resolution logic for Livewire components that
 * need an AI model selector.
 *
 * Models are identified by a composite key: "providerId:::modelId".
 * This ensures the correct provider credentials are used when the model
 * belongs to a different provider than the primary config.
 */
trait ResolvesAvailableModels
{
    /**
     * Composite separator for provider/model IDs.
     */
    private const MODEL_ID_SEPARATOR = ':::';

    /**
     * Load all active models across active providers for a company, grouped by provider.
     *
     * @return list<array{id: string, label: string, provider: string, providerId: int}>
     */
    protected function loadAvailableModels(int $companyId): array
    {
        $providers = AiProvider::query()
            ->forCompany($companyId)
            ->active()
            ->orderBy('priority')
            ->orderBy('display_name')
            ->get();

        $models = [];

        foreach ($providers as $provider) {
            $providerModels = AiProviderModel::query()
                ->where('ai_provider_id', $provider->id)
                ->active()
                ->orderBy('model_id')
                ->get();

            foreach ($providerModels as $model) {
                $models[] = [
                    'id' => $provider->id.self::MODEL_ID_SEPARATOR.$model->model_id,
                    'label' => $model->model_id,
                    'provider' => $provider->display_name,
                    'providerId' => (int) $provider->id,
                ];
            }
        }

        return $models;
    }

    /**
     * Resolve the default composite model ID (highest-priority provider + its default model).
     */
    protected function resolveDefaultCompositeModelId(int $companyId): string
    {
        $provider = AiProvider::query()
            ->forCompany($companyId)
            ->active()
            ->prioritized()
            ->first();

        if ($provider === null) {
            $provider = AiProvider::query()
                ->forCompany($companyId)
                ->active()
                ->orderBy('display_name')
                ->first();
        }

        if ($provider === null) {
            return '';
        }

        $model = AiProviderModel::query()
            ->where('ai_provider_id', $provider->id)
            ->active()
            ->default()
            ->first();

        if ($model === null) {
            $model = AiProviderModel::query()
                ->where('ai_provider_id', $provider->id)
                ->active()
                ->orderBy('model_id')
                ->first();
        }

        return $model !== null
            ? $provider->id.self::MODEL_ID_SEPARATOR.$model->model_id
            : '';
    }

    /**
     * Resolve provider credentials and model from a composite "providerId:::modelId" string.
     *
     * @return array{api_key: string, base_url: string, model: string, provider_name: string}|array{error: string}
     */
    protected function resolveModelConfigFromComposite(string $compositeId): array
    {
        $parts = explode(self::MODEL_ID_SEPARATOR, $compositeId, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return ['error' => __('Invalid model selection. Please choose a model and try again.')];
        }

        [$providerId, $modelId] = $parts;

        $provider = AiProvider::query()
            ->where('id', $providerId)
            ->active()
            ->first();

        if ($provider === null) {
            return ['error' => __('The selected AI provider is no longer available. Please choose another model.')];
        }

        $modelExists = AiProviderModel::query()
            ->where('ai_provider_id', $provider->id)
            ->where('model_id', $modelId)
            ->active()
            ->exists();

        if (! $modelExists) {
            return ['error' => __('Model ":model" is not available on provider ":provider". Please re-select the model.', [
                'model' => $modelId,
                'provider' => $provider->display_name,
            ])];
        }

        $credentials = app(RuntimeCredentialResolver::class)->resolve([
            'api_key' => $provider->api_key,
            'base_url' => $provider->base_url,
            'provider_name' => $provider->name,
        ]);

        if (isset($credentials['runtime_error'])) {
            return ['error' => $credentials['runtime_error']->userMessage];
        }

        return [
            'api_key' => $credentials['api_key'],
            'base_url' => $credentials['base_url'],
            'model' => $modelId,
            'provider_name' => $provider->name,
        ];
    }

    /**
     * Extract just the model_id from a composite "providerId:::modelId" string.
     */
    protected function extractModelId(string $compositeId): ?string
    {
        $parts = explode(self::MODEL_ID_SEPARATOR, $compositeId, 2);

        return count($parts) === 2 ? $parts[1] : null;
    }
}
