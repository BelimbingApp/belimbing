<?php

namespace App\Base\Media\PhotoCleanup\Contracts;

use App\Modules\Core\AI\Models\AiProvider;

/**
 * Company-scoped credential access for Vision (image) providers stored in
 * {@see AiProvider} rows with family {@code image}.
 */
interface ImageProviderCredentialStore
{
    public function apiKey(?int $companyId, string $providerKey): ?string;

    /**
     * @return array<string, mixed>
     */
    public function connectionConfig(?int $companyId, string $providerKey): array;

    public function hasCredential(int $companyId, string $providerKey, string $credentialKey = 'api_key'): bool;

    /**
     * @param  array{display_name: string, base_url: string, credentials?: array<string, mixed>, connection_config?: array<string, mixed>}  $attributes
     */
    public function upsert(int $companyId, string $providerKey, array $attributes, ?int $createdBy = null): void;

    public function delete(int $companyId, string $providerKey): void;
}
