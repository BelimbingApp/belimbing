<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\Exceptions\GithubCopilotAuthException;
use App\Base\AI\Exceptions\ModelCatalogSyncException;
use App\Base\AI\Exceptions\ProviderDiscoveryException;
use App\Base\AI\Services\ModelCatalogService;
use App\Base\AI\Services\ProviderDiscoveryService;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use RuntimeException;

/**
 * Discovers and syncs models for company-scoped AI providers.
 *
 * Delegates stateless operations to Base services:
 *   - ProviderDiscoveryService for API discovery (GET /models)
 *   - ModelCatalogService for community catalog data (models.dev)
 *   - ProviderDefinitionRegistry for credential resolution
 *
 * Handles company-scoped concerns: DB upsert, default model selection,
 * catalog enrichment fallback.
 */
class ModelDiscoveryService
{
    public function __construct(
        private readonly ProviderDefinitionRegistry $registry,
        private readonly ProviderDiscoveryService $providerDiscovery,
        private readonly ModelCatalogService $modelCatalog,
    ) {}

    /**
     * Discover available models from a provider's API.
     *
     * Resolves runtime credentials through the provider's definition,
     * which handles provider-specific transformations (e.g. token exchange).
     *
     * @param  AiProvider  $provider  Provider with base_url and credentials
     * @return list<array{model_id: string, display_name: string}>
     *
     * @throws GithubCopilotAuthException|ProviderDiscoveryException
     */
    public function discoverModels(AiProvider $provider): array
    {
        $definition = $this->registry->for($provider->name);
        $resolved = $definition->resolveRuntime($provider);

        return $this->providerDiscovery->discoverModels(
            rtrim($resolved->baseUrl, '/'),
            $resolved->apiKey ?? '',
        );
    }

    /**
     * Sync discovered models into the database for a provider.
     *
     * Upserts models: adds new ones from API discovery. Catalog metadata
     * (display_name, costs, etc.) is served from ModelCatalogService at read
     * time — only model_id and admin config (is_active, cost_override) are stored.
     *
     * If API discovery fails, falls back to importing from the models.dev catalog.
     *
     * @param  AiProvider  $provider  Provider to sync models for
     * @return array{added: int, updated: int, total: int}
     *
     * @throws ModelCatalogSyncException When the models.dev catalog cannot be downloaded
     */
    public function syncModels(AiProvider $provider): array
    {
        $this->modelCatalog->ensureSynced();

        try {
            $discovered = $this->discoverModels($provider);
        } catch (RuntimeException) {
            return $this->importFromCatalog($provider);
        }

        if ($discovered === []) {
            return $this->importFromCatalog($provider);
        }

        $added = 0;
        $updated = 0;

        foreach ($discovered as $model) {
            $modelId = $model['model_id'] ?? null;

            if (! is_string($modelId) || $modelId === '') {
                continue;
            }

            $providerModel = AiProviderModel::query()->firstOrCreate([
                'ai_provider_id' => $provider->id,
                'model_id' => $modelId,
            ]);

            if ($providerModel->wasRecentlyCreated) {
                $added++;

                continue;
            }

            $updated++;
        }

        // Auto-set default model if none exists for this provider
        $this->ensureDefaultModel($provider);

        return [
            'added' => $added,
            'updated' => $updated,
            'total' => count($discovered),
        ];
    }

    /**
     * Import models from the models.dev community catalog.
     *
     * Used as fallback when API discovery fails or returns no models.
     *
     * @return array{added: int, updated: int, total: int}
     */
    public function importFromCatalog(AiProvider $provider): array
    {
        $catalogModels = $this->modelCatalog->getModels($provider->name);

        if ($catalogModels === []) {
            return ['added' => 0, 'updated' => 0, 'total' => 0];
        }

        $added = 0;

        foreach ($catalogModels as $modelId => $modelData) {
            $id = is_string($modelId) ? $modelId : ($modelData['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $providerModel = AiProviderModel::query()->firstOrCreate([
                'ai_provider_id' => $provider->id,
                'model_id' => $id,
            ]);

            if ($providerModel->wasRecentlyCreated) {
                $added++;
            }
        }

        // Auto-set default model if none exists for this provider
        $this->ensureDefaultModel($provider);

        return [
            'added' => $added,
            'updated' => 0,
            'total' => count($catalogModels),
        ];
    }

    /**
     * Ensure a provider has a default model set.
     *
     * Falls back to the first active model ordered by model_id.
     */
    public function ensureDefaultModel(AiProvider $provider): void
    {
        $hasDefault = AiProviderModel::query()
            ->where('ai_provider_id', $provider->id)
            ->where('is_default', true)
            ->exists();

        if ($hasDefault) {
            return;
        }

        $candidate = AiProviderModel::query()
            ->where('ai_provider_id', $provider->id)
            ->where('is_active', true)
            ->orderBy('model_id')
            ->first();

        if ($candidate instanceof AiProviderModel) {
            $candidate->setAsDefault();
        }
    }
}
