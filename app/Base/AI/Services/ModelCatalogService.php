<?php
namespace App\Base\AI\Services;

use App\Base\AI\DTO\CatalogSyncResult;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Exceptions\ModelCatalogSyncException;
use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Base\Support\File as BlbFile;
use App\Base\Support\Json as BlbJson;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Fetches, caches, and serves the models.dev community model catalog.
 *
 * Data is cached as a JSON file in storage/download/ai/models-dev/ following
 * the Geonames download cache pattern. Uses HTTP ETag headers for conditional
 * requests to avoid unnecessary re-downloads.
 *
 * The catalog is merged with a thin provider overlay from config('ai.provider_overlay')
 * that supplies BLB-specific fields (base_url, auth_type, api_key_url) not present
 * in models.dev data.
 */
class ModelCatalogService
{
    private const CATALOG_URL = 'https://models.dev/api.json';

    private const CATALOG_DIR = 'download/ai/models-dev';

    private const CATALOG_FILE = 'catalog.json';

    private const META_FILE = 'catalog.meta.json';

    private const CATALOG_SYNC_LOCK = 'ai:model-catalog-sync';

    private const CATALOG_SYNC_LOCK_TTL_SECONDS = 60;

    private const CATALOG_SYNC_LOCK_WAIT_SECONDS = 45;

    private ?array $catalogCache = null;

    public function __construct(
        private readonly ?IntegrationGateway $gateway = null,
    ) {}

    /**
     * Ensure the models.dev catalog is on disk (refresh via conditional GET if needed).
     *
     * Called before provider model sync so catalog fallback works without a manual
     * `blb:ai:catalog:sync` run. Uses a cache lock so concurrent syncs do not race.
     *
     * @throws ModelCatalogSyncException
     */
    public function ensureSynced(): void
    {
        try {
            Cache::lock(self::CATALOG_SYNC_LOCK, self::CATALOG_SYNC_LOCK_TTL_SECONDS)
                ->block(self::CATALOG_SYNC_LOCK_WAIT_SECONDS, function (): void {
                    $this->sync(force: false);
                });
        } catch (LockTimeoutException $e) {
            throw ModelCatalogSyncException::lockTimeout(self::CATALOG_SYNC_LOCK_WAIT_SECONDS, $e);
        }
    }

    /**
     * Fetch api.json with ETag conditional request, write to storage/download/ai/.
     *
     * Sends an If-None-Match header if we have a cached ETag. On 304 Not Modified,
     * no data is written. On 200 OK, the full response is saved to disk.
     *
     * @throws ModelCatalogSyncException
     */
    public function sync(bool $force = false): CatalogSyncResult
    {
        $catalogPath = $this->catalogPath();

        $currentMeta = $this->readMeta();
        $currentEtag = $force ? null : ($currentMeta['etag'] ?? null);

        $headers = [];
        if ($currentEtag !== null) {
            $headers['If-None-Match'] = $currentEtag;
        }

        $response = $this->integrationGateway()->send(new IntegrationRequest(
            system: 'ai_catalog',
            operation: 'ai.catalog.models_dev.sync',
            method: 'GET',
            endpoint: self::CATALOG_URL,
            protocolOperation: 'GET /api.json',
            provider: 'models.dev',
            headers: $headers,
            timeoutSeconds: 30,
            metadata: [
                'force' => $force,
                'current_etag' => $currentEtag,
            ],
        ));

        if ($response->status === 304) {
            $this->writeMeta($currentMeta['etag'] ?? '', now()->toIso8601String());

            $stats = $this->countStats($this->readCatalogFile());

            return new CatalogSyncResult(
                updated: false,
                etag: $currentMeta['etag'] ?? '',
                providerCount: $stats['providers'],
                modelCount: $stats['models'],
            );
        }

        if (! $response->successful()) {
            throw ModelCatalogSyncException::httpFailure((int) ($response->status ?? 0), $response->exchange?->id);
        }

        $data = $response->json();

        if (! is_array($data) || $data === []) {
            throw ModelCatalogSyncException::invalidPayload($response->exchange?->id);
        }

        $etag = (string) ($response->headers['ETag'][0] ?? $response->headers['etag'][0] ?? '');

        BlbFile::put($catalogPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->writeMeta($etag, now()->toIso8601String());

        $this->catalogCache = null;

        $stats = $this->countStats($data);

        return new CatalogSyncResult(
            updated: true,
            etag: $etag,
            providerCount: $stats['providers'],
            modelCount: $stats['models'],
        );
    }

    /**
     * Full parsed catalog from cached file.
     *
     * @return array<string, array<string, mixed>> Provider ID → provider data
     */
    public function getCatalog(): array
    {
        if ($this->catalogCache !== null) {
            return $this->catalogCache;
        }

        $data = $this->readCatalogFile();

        $this->catalogCache = is_array($data) ? $data : [];

        return $this->catalogCache;
    }

    /**
     * Single provider with merged overlay (base_url, auth_type, api_key_url).
     *
     * @return array<string, mixed>|null Provider data or null if not found
     */
    public function getProvider(string $id): ?array
    {
        $providers = $this->getProviders();

        return $providers[$id] ?? null;
    }

    /**
     * All providers from catalog + overlay-only providers (local/self-hosted).
     *
     * Maps catalog fields to BLB conventions:
     *   catalog `api`  → `base_url`
     *   catalog `name` → `display_name`
     *   catalog `doc`  → `doc_url`
     *
     * Overlay fields (`auth_type`, `api_key_url`, `base_url`) override catalog
     * values when explicitly set. Default `auth_type` is `'api_key'`.
     *
     * @return array<string, array<string, mixed>> Provider ID → merged provider data
     */
    public function getProviders(): array
    {
        $catalog = $this->getCatalog();
        $overlay = config('ai.provider_overlay', []);

        $merged = [];

        // Start with catalog providers, map fields + merge overlay
        foreach ($catalog as $id => $providerData) {
            $mapped = $providerData;
            $mapped['base_url'] = $providerData['api'] ?? '';
            $mapped['display_name'] = $providerData['name'] ?? $id;
            $mapped['doc_url'] = $providerData['doc'] ?? '';
            $mapped['auth_type'] = 'api_key';

            if (isset($overlay[$id])) {
                $mapped = array_merge($mapped, $overlay[$id]);
            }

            $merged[$id] = $mapped;
        }

        // Add overlay-only providers (not in catalog)
        foreach ($overlay as $id => $overlayData) {
            if (! isset($merged[$id])) {
                $merged[$id] = array_merge([
                    'id' => $id,
                    'display_name' => $overlayData['display_name'] ?? $id,
                    'base_url' => '',
                    'auth_type' => 'api_key',
                ], $overlayData);
            }
        }

        // Ensure every provider has category and region tags
        foreach ($merged as $id => &$provider) {
            $provider['category'] ??= ['specialized'];
            $provider['region'] ??= ['global'];
        }
        unset($provider);

        return $merged;
    }

    /**
     * All models for a provider, merged from catalog.
     *
     * @return array<string, array<string, mixed>> Model ID → model data
     */
    public function getModels(string $providerId): array
    {
        $provider = $this->getCatalog()[$providerId] ?? null;

        if ($provider === null || ! isset($provider['models'])) {
            return [];
        }

        return is_array($provider['models']) ? $provider['models'] : [];
    }

    /**
     * Whether the cache file exceeds max age.
     *
     * @param  int  $maxAgeDays  Maximum age in days before considered stale
     */
    public function isStale(int $maxAgeDays = 7): bool
    {
        $lastSynced = $this->lastSyncedAt();

        if ($lastSynced === null) {
            return true;
        }

        $now = new DateTimeImmutable;
        $diff = $now->getTimestamp() - $lastSynced->getTimestamp();

        return $diff > ($maxAgeDays * 86400);
    }

    /**
     * Timestamp of last successful sync.
     */
    public function lastSyncedAt(): ?DateTimeImmutable
    {
        $meta = $this->readMeta();
        $synced = $meta['last_synced'] ?? null;

        if ($synced === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($synced);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Read the cached catalog file.
     *
     * @return array<string, mixed>|null
     */
    private function readCatalogFile(): ?array
    {
        $path = $this->catalogPath();

        if (! file_exists($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return null;
        }

        return BlbJson::decodeArray($content);
    }

    /**
     * Read the catalog metadata file.
     *
     * @return array{etag?: string, last_synced?: string}
     */
    private function readMeta(): array
    {
        $path = $this->metaPath();

        if (! file_exists($path)) {
            return [];
        }

        $content = file_get_contents($path);

        return $content === false ? [] : BlbJson::decodeArray($content) ?? [];
    }

    /**
     * Write the catalog metadata file.
     */
    private function writeMeta(string $etag, string $lastSynced): void
    {
        BlbFile::put($this->metaPath(), json_encode([
            'etag' => $etag,
            'last_synced' => $lastSynced,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * Count providers and models in catalog data.
     *
     * @return array{providers: int, models: int}
     */
    private function countStats(?array $data): array
    {
        if ($data === null) {
            return ['providers' => 0, 'models' => 0];
        }

        $models = 0;

        foreach ($data as $provider) {
            if (is_array($provider) && isset($provider['models']) && is_array($provider['models'])) {
                $models += count($provider['models']);
            }
        }

        return ['providers' => count($data), 'models' => $models];
    }

    private function catalogPath(): string
    {
        return storage_path(self::CATALOG_DIR.'/'.self::CATALOG_FILE);
    }

    /**
     * Resolve the API type for a provider+model pair.
     *
     * Checks `api_type_overrides` glob patterns in the provider overlay config.
     * Falls back to OpenAiChatCompletions when no pattern matches.
     *
     * @param  string|null  $providerName  Provider identifier (e.g. 'openai', 'github-copilot')
     * @param  string  $modelId  Model identifier (e.g. 'gpt-5.4', 'claude-sonnet-4')
     */
    public function resolveApiType(?string $providerName, string $modelId): AiApiType
    {
        if ($providerName === null || $providerName === '') {
            return AiApiType::OpenAiChatCompletions;
        }

        $overlay = config("ai.provider_overlay.{$providerName}.api_type_overrides", []);

        foreach ($overlay as $pattern => $apiTypeValue) {
            if (fnmatch($pattern, $modelId)) {
                $resolved = AiApiType::tryFrom($apiTypeValue);

                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return AiApiType::OpenAiChatCompletions;
    }

    private function metaPath(): string
    {
        return storage_path(self::CATALOG_DIR.'/'.self::META_FILE);
    }

    private function integrationGateway(): IntegrationGateway
    {
        return $this->gateway ?? app(IntegrationGateway::class);
    }
}
