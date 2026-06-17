<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Base\Media\PhotoCleanup\Contracts\PhotoCleanupProvider;

/**
 * Thin client for PhotoRoom's Remove Background API
 * (POST /v1/segment). Sandbox and live share the same endpoint; only the
 * resolved API key differs, and sandbox output carries a PhotoRoom watermark.
 *
 * The default {@see PhotoCleanupProvider} implementation.
 */
class PhotoRoomClient implements PhotoCleanupProvider
{
    private const SEGMENT_ENDPOINT = '/v1/segment';

    public function __construct(
        private readonly PhotoRoomConfiguration $configuration,
        private readonly IntegrationGateway $integration,
    ) {}

    /**
     * @return array{bytes: string, provider: string, provider_label: string}
     */
    public function removeBackground(string $imageBytes, string $filename, string $mimeType, ?int $companyId = null): array
    {
        $config = $this->configuration->requireConfigured($companyId);

        $boundary = 'PhotoRoom'.bin2hex(random_bytes(16));
        $body = "--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"image_file\"; filename=\"{$filename}\"\r\n"
            ."Content-Type: {$mimeType}\r\n\r\n"
            .$imageBytes."\r\n"
            ."--{$boundary}--\r\n";

        $response = $this->integration->send(new IntegrationRequest(
            system: PhotoRoomConfiguration::PROVIDER,
            operation: 'media.photo_cleanup.remove_background',
            method: 'POST',
            endpoint: rtrim($config['api_base_url'], '/').self::SEGMENT_ENDPOINT,
            protocol: 'rest',
            protocolOperation: 'remove_background',
            provider: PhotoRoomConfiguration::PROVIDER,
            headers: [
                'x-api-key' => $config['api_key'],
                'Content-Type' => 'multipart/form-data; boundary='.$boundary,
            ],
            body: $body,
            ownerType: $companyId !== null ? 'company' : null,
            ownerId: $companyId,
            timeoutSeconds: 60,
            retryTimes: 1,
            asJson: false,
        ));

        if ($response->failed()) {
            throw PhotoCleanupException::requestFailed($response->status, $response->exchange?->id);
        }

        return [
            'bytes' => $response->body,
            'provider' => PhotoRoomConfiguration::PROVIDER,
            'provider_label' => PhotoRoomConfiguration::PROVIDER_LABEL,
        ];
    }
}
