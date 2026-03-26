<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Model sync state and actions for the provider manager component.
 *
 * Handles live API model discovery with flash messages for success and
 * persistent error display for connection failures.
 */
trait ManagesSync
{
    public ?string $syncMessage = null;

    /** Persistent sync error (connection failures — not auto-dismissed) */
    public ?string $syncError = null;

    /** Provider ID the current syncError belongs to */
    public ?int $syncErrorProviderId = null;

    /**
     * Sync models for a provider from its live API, with template fallback.
     */
    public function syncProviderModels(int $providerId): void
    {
        $provider = AiProvider::query()->find($providerId);

        if (! $provider) {
            return;
        }

        if ($this->syncErrorProviderId === $providerId) {
            $this->syncError = null;
            $this->syncErrorProviderId = null;
        }

        try {
            $result = app(ModelDiscoveryService::class)->syncModels($provider);
        } catch (ConnectionException $e) {
            $this->syncError = __('Could not connect to :url — is the server running?', [
                'url' => $provider->base_url,
            ]);
            $this->syncErrorProviderId = $providerId;

            Log::warning('Model sync failed', [
                'provider' => $provider->name,
                'base_url' => $provider->base_url,
                'error' => $e->getMessage(),
            ]);

            return;
        } catch (\Exception $e) {
            $this->syncError = __('Sync failed: :message', ['message' => $e->getMessage()]);
            $this->syncErrorProviderId = $providerId;

            Log::warning('Model sync failed', [
                'provider' => $provider->name,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->syncMessage = match (true) {
            $result['added'] > 0 && $result['updated'] > 0 => __('Added :added, updated :updated models.', [
                'added' => $result['added'],
                'updated' => $result['updated'],
            ]),
            $result['added'] > 0 => __('Added :count new models.', ['count' => $result['added']]),
            $result['updated'] > 0 => __('Updated :count models.', ['count' => $result['updated']]),
            ($result['total'] ?? 0) === 0 => __('No models are listed in the catalog for this provider.'),
            default => __('Models are up to date.'),
        };
    }

    /**
     * Dismiss the persistent sync error.
     */
    public function clearSyncError(): void
    {
        $this->syncError = null;
        $this->syncErrorProviderId = null;
    }
}
