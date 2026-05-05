<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Concerns;

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Values\ModelsDiscoveryProfile;
use App\Modules\Core\AI\Values\ProviderAdvancedSetting;
use App\Modules\Core\AI\Values\ResolvedProviderConfig;

trait HasDefaultProviderCapabilities
{
    /**
     * @return list<ProviderAdvancedSetting>
     */
    public function advancedSettings(): array
    {
        // Keep signature compatible with ProviderDefinition; parameter may be unused by default implementations.
        return [];
    }

    public function modelsDiscoveryProfile(AiProvider $provider, ResolvedProviderConfig $resolved): ModelsDiscoveryProfile
    {
        $provider->getKey();

        return new ModelsDiscoveryProfile(
            baseUrl: rtrim($resolved->baseUrl, '/'),
            headers: $this->normalizedHeaders($resolved),
            query: [],
        );
    }

    public function discoverModels(AiProvider $provider): ?array
    {
        $provider->getKey();

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function normalizedHeaders(ResolvedProviderConfig $resolved): array
    {
        $headers = [];

        foreach ($resolved->headers as $name => $value) {
            if (is_string($name) && $name !== '' && is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
