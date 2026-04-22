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

        $source = (string) ($result['source'] ?? 'provider_api');
        $added = (int) ($result['added'] ?? 0);
        $updated = (int) ($result['updated'] ?? 0);
        $deactivated = (int) ($result['deactivated'] ?? 0);
        $total = (int) ($result['total'] ?? 0);

        $this->syncMessage = match ($source) {
            'provider_definition' => match (true) {
                $total === 0 => __('BLB did not define any fallback models for this provider.'),
                $deactivated > 0 => __('BLB checked this provider against its curated model list: :total supported models remain active locally, :deactivated', [
                    'total' => $total,
                    'deactivated' => trans_choice('{1} 1 unsupported local model deactivated.|[2,*] :count unsupported local models deactivated.', $deactivated, [
                        'count' => $deactivated,
                    ]),
                ]),
                default => __('BLB checked this provider against its curated model list: :total supported models are active locally.', [
                    'total' => $total,
                ]),
            },
            'catalog' => match (true) {
                $total === 0 => __('No models are listed in the catalog for this provider.'),
                $added > 0 => __('Imported :count catalog models into the local list.', ['count' => $added]),
                default => __('The local model list already matches the catalog fallback.'),
            },
            default => match (true) {
                $added > 0 && $updated > 0 => __('Added :added models and refreshed :updated existing entries from the provider.', [
                    'added' => $added,
                    'updated' => $updated,
                ]),
                $added > 0 => __('Added :count models from the provider.', ['count' => $added]),
                $updated > 0 => __('Refreshed :count existing provider models.', ['count' => $updated]),
                $total === 0 => __('The provider returned no models.'),
                default => __('The local model list already matches the provider response.'),
            },
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
