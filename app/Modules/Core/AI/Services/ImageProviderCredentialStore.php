<?php

namespace App\Modules\Core\AI\Services;

use App\Base\Media\PhotoCleanup\Contracts\ImageProviderCredentialStore as ImageProviderCredentialStoreContract;
use App\Modules\Core\AI\Enums\AuthType;
use App\Modules\Core\AI\Models\AiProvider;

final class ImageProviderCredentialStore implements ImageProviderCredentialStoreContract
{
    public function apiKey(?int $companyId, string $providerKey): ?string
    {
        if ($companyId === null) {
            return null;
        }

        $value = data_get($this->find($companyId, $providerKey)?->credentials, 'api_key');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function connectionConfig(?int $companyId, string $providerKey): array
    {
        if ($companyId === null) {
            return [];
        }

        $config = $this->find($companyId, $providerKey)?->connection_config;

        return is_array($config) ? $config : [];
    }

    public function hasCredential(int $companyId, string $providerKey, string $credentialKey = 'api_key'): bool
    {
        $value = data_get($this->find($companyId, $providerKey)?->credentials, $credentialKey);

        return is_string($value) && trim($value) !== '';
    }

    public function upsert(int $companyId, string $providerKey, array $attributes, ?int $createdBy = null): void
    {
        $existing = $this->find($companyId, $providerKey);

        if ($existing !== null) {
            if (isset($attributes['credentials'])) {
                $attributes['credentials'] = array_merge(
                    is_array($existing->credentials) ? $existing->credentials : [],
                    $attributes['credentials'],
                );
            }

            if (isset($attributes['connection_config'])) {
                $attributes['connection_config'] = array_merge(
                    is_array($existing->connection_config) ? $existing->connection_config : [],
                    $attributes['connection_config'],
                );
            }

            $existing->update($attributes);

            return;
        }

        AiProvider::query()->create(array_merge([
            'company_id' => $companyId,
            'name' => $providerKey,
            'family' => AiProvider::FAMILY_IMAGE,
            'auth_type' => AuthType::ApiKey,
            'is_active' => true,
            'priority' => 0,
            'created_by' => $createdBy,
            'credentials' => [],
            'connection_config' => [],
        ], $attributes));
    }

    public function delete(int $companyId, string $providerKey): void
    {
        AiProvider::query()
            ->forCompany($companyId)
            ->image()
            ->where('name', $providerKey)
            ->delete();
    }

    private function find(int $companyId, string $providerKey): ?AiProvider
    {
        return AiProvider::query()
            ->forCompany($companyId)
            ->image()
            ->where('name', $providerKey)
            ->first();
    }
}
