<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\AI\Exceptions\GithubCopilotAuthException;
use App\Base\AI\Exceptions\ModelCatalogSyncException;
use App\Base\AI\Exceptions\ProviderDiscoveryException;
use App\Base\AI\Services\ModelCatalogService;
use App\Base\AI\Services\ProviderDiscoveryService;
use App\Base\Foundation\Exceptions\BlbException;
use App\Base\Integration\Models\OutboundExchange;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use Illuminate\Support\Facades\Schema;
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
        $definitionModels = $definition->discoverModels($provider);

        if ($definitionModels !== null) {
            return $definitionModels;
        }

        $resolved = $definition->resolveRuntime($provider);
        $http = $definition->modelsDiscoveryProfile($provider, $resolved);

        return $this->providerDiscovery->discoverModels(
            $http->baseUrl,
            $resolved->apiKey ?? '',
            $http->headers,
            $http->query,
        );
    }

    /**
     * Sync discovered models into the database for a provider.
     *
     * Upserts models: adds new ones from API or provider-definition discovery. Catalog metadata
     * (display_name, costs, etc.) is served from ModelCatalogService at read
     * time — only model_id and admin config (is_active, cost_override) are stored.
     *
     * When the provider supplies a fixed definition-owned list (authoritative sync), any local
     * rows whose {@see AiProviderModel::$model_id} is not in that list are deleted.
     *
     * If API discovery fails, falls back to importing from the models.dev catalog.
     *
     * @param  AiProvider  $provider  Provider to sync models for
     * @return array{added: int, updated: int, total: int, deactivated: int, source: string}
     *
     * @throws ModelCatalogSyncException When the models.dev catalog cannot be downloaded
     */
    public function syncModels(AiProvider $provider): array
    {
        $definition = $this->registry->for($provider->name);
        $definitionModels = $definition->discoverModels($provider);

        if ($definitionModels !== null) {
            $summary = $this->syncDiscoveredModels(
                $provider,
                $definitionModels,
                authoritative: true,
                source: 'provider_definition',
            );

            // Definition-owned sync may not perform any discovery HTTP request, so there may be no exchange.
            return [...$summary, 'exchange_id' => null];
        }

        $exchangeId = null;

        try {
            $discovered = $this->discoverModels($provider);
        } catch (RuntimeException $e) {
            $exchangeId = $this->markProviderDiscoveryFallback(
                $provider,
                'provider_discovery_failed',
                $e,
                fallbackProvider: 'models.dev',
            );

            $this->modelCatalog->ensureSynced();

            return [
                ...$this->importFromCatalog($provider),
                'exchange_id' => $exchangeId,
            ];
        }

        if ($discovered === []) {
            $exchangeId = $this->markProviderDiscoveryFallback(
                $provider,
                'empty_provider_discovery',
                fallbackProvider: 'models.dev',
            );

            $this->modelCatalog->ensureSynced();

            return [
                ...$this->importFromCatalog($provider),
                'exchange_id' => $exchangeId,
            ];
        }

        return [
            ...$this->syncDiscoveredModels($provider, $discovered, source: 'provider_api'),
            'exchange_id' => $this->latestProviderDiscoveryExchange($provider)?->id,
        ];
    }

    /**
     * Persist discovered models and ensure a default exists.
     *
     * @param  list<array{model_id: string, display_name: string}>  $discovered
     * @return array{added: int, updated: int, total: int, deactivated: int, source: string}
     */
    private function syncDiscoveredModels(
        AiProvider $provider,
        array $discovered,
        bool $authoritative = false,
        string $source = 'provider_api',
    ): array {
        if ($discovered === []) {
            return ['added' => 0, 'updated' => 0, 'total' => 0, 'deactivated' => 0, 'source' => $source];
        }

        $added = 0;
        $updated = 0;
        $deactivated = 0;

        $discoveredIds = [];

        foreach ($discovered as $model) {
            $modelId = $model['model_id'] ?? null;

            if (! is_string($modelId) || $modelId === '') {
                continue;
            }

            $discoveredIds[] = $modelId;

            $providerModel = AiProviderModel::query()->firstOrCreate([
                'ai_provider_id' => $provider->id,
                'model_id' => $modelId,
            ]);

            if ($providerModel->wasRecentlyCreated) {
                // New discoveries default to active so they're immediately usable.
                // Existing rows keep the admin's explicit is_active choice.
                $providerModel->update(['is_active' => true]);
                $added++;

                continue;
            }

            $updated++;
        }

        if ($authoritative) {
            $deactivated = $this->deleteMissingModels($provider, $discoveredIds);
        }

        $this->unsetInactiveDefaultModel($provider);

        return [
            'added' => $added,
            'updated' => $updated,
            'total' => count($discovered),
            'deactivated' => $deactivated,
            'source' => $source,
        ];
    }

    /**
     * Import models from the models.dev community catalog.
     *
     * Used as fallback when API discovery fails or returns no models.
     *
     * @return array{added: int, updated: int, total: int, deactivated: int, source: string}
     */
    public function importFromCatalog(AiProvider $provider): array
    {
        $catalogModels = $this->modelCatalog->getModels($provider->name);

        if ($catalogModels === []) {
            return ['added' => 0, 'updated' => 0, 'total' => 0, 'deactivated' => 0, 'source' => 'catalog'];
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

        $this->unsetInactiveDefaultModel($provider);

        return [
            'added' => $added,
            'updated' => 0,
            'total' => count($catalogModels),
            'deactivated' => 0,
            'source' => 'catalog',
        ];
    }

    private function markProviderDiscoveryFallback(
        AiProvider $provider,
        string $reason,
        ?RuntimeException $exception = null,
        string $fallbackProvider = 'models.dev',
    ): ?string {
        if (! Schema::hasTable('base_integration_outbound_exchanges')) {
            return null;
        }

        $exchange = $this->exchangeFromException($exception)
            ?? OutboundExchange::query()
                ->where('system', 'ai_provider')
                ->where('operation', 'ai.provider.models.discover')
                ->where('provider', $this->providerNameFromBaseUrl($provider->base_url))
                ->latest('occurred_at')
                ->first();

        if (! $exchange instanceof OutboundExchange) {
            return null;
        }

        $metadata = $exchange->metadata ?? [];
        $metadata['fallback_provider'] = $fallbackProvider;

        $exchange->update([
            'fallback_used' => true,
            'fallback_reason' => $reason,
            'metadata' => $metadata,
        ]);

        return $exchange->id;
    }

    private function exchangeFromException(?RuntimeException $exception): ?OutboundExchange
    {
        if (! $exception instanceof BlbException) {
            return null;
        }

        $exchangeId = $exception->context['exchange_id'] ?? null;

        return is_string($exchangeId) && $exchangeId !== ''
            ? OutboundExchange::query()->find($exchangeId)
            : null;
    }

    private function providerNameFromBaseUrl(?string $baseUrl): ?string
    {
        $host = is_string($baseUrl) ? parse_url($baseUrl, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function latestProviderDiscoveryExchange(AiProvider $provider): ?OutboundExchange
    {
        if (! Schema::hasTable('base_integration_outbound_exchanges')) {
            return null;
        }

        return OutboundExchange::query()
            ->where('system', 'ai_provider')
            ->where('operation', 'ai.provider.models.discover')
            ->where('provider', $this->providerNameFromBaseUrl($provider->base_url))
            ->latest('occurred_at')
            ->first();
    }

    /**
     * Operator selects the default model. Sync should not invent one, but it should
     * clear an invalid default (e.g. deactivated model) so UI/runtime can surface
     * a deterministic configuration error.
     */
    private function unsetInactiveDefaultModel(AiProvider $provider): void
    {
        $default = AiProviderModel::query()
            ->where('ai_provider_id', $provider->id)
            ->where('is_default', true)
            ->first();

        if ($default instanceof AiProviderModel && ! $default->is_active) {
            $default->unsetDefault();
        }
    }

    /**
     * Remove local rows whose model_id is not on the authoritative list (authoritative sync).
     *
     * Unlike {@see deactivateMissingModels()}, this drops already-inactive orphans so
     * they do not linger in the admin model table after "Sync models".
     *
     * @param  list<string>  $discoveredIds
     */
    private function deleteMissingModels(AiProvider $provider, array $discoveredIds): int
    {
        return AiProviderModel::query()
            ->where('ai_provider_id', $provider->id)
            ->whereNotIn('model_id', $discoveredIds)
            ->delete();
    }
}
