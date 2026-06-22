<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Base\Media\PhotoCleanup\Contracts\PhotoCleanupProvider;

/**
 * Thin client for Poof's Remove Background API (`POST /v1/remove` on
 * `api.poof.bg`). Auth is a single `x-api-key` header; the request is
 * multipart `image_file` plus `size=auto`; the response is the cleaned PNG
 * body. Poof exposes no documented cheap read or probe endpoint, so this client
 * does not implement `TestsConnection` — its `Ready` state follows from a real
 * cleanup run, and the operator surface offers no "Test connection" for it.
 * See docs/plans/media-photo-cleanup-providers.md.
 */
class PoofClient implements PhotoCleanupProvider
{
    private const REMOVE_ENDPOINT = '/remove';

    /**
     * Output resolution for the cleaned image. Poof's `size` field accepts only
     * `preview` | `medium` | `hd` | `full` — the marketing-site curl example
     * (`size=auto`) is rejected by the API with a validation_error. `full`
     * matches the cleanup intent: a full-resolution transparent PNG for
     * downstream product listings, consistent with PhotoRoom's `full` default.
     */
    private const OUTPUT_SIZE = 'full';

    public function __construct(
        private readonly PoofConfiguration $configuration,
        private readonly IntegrationGateway $integration,
    ) {}

    /**
     * @return array{bytes: string, provider: string, provider_label: string}
     */
    public function removeBackground(string $imageBytes, string $filename, string $mimeType, ?int $companyId = null): array
    {
        $config = $this->configuration->requireConfigured($companyId);

        $boundary = 'Poof'.bin2hex(random_bytes(16));
        $body = "--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"image_file\"; filename=\"{$filename}\"\r\n"
            ."Content-Type: {$mimeType}\r\n\r\n"
            .$imageBytes."\r\n"
            ."--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"size\"\r\n\r\n"
            .self::OUTPUT_SIZE."\r\n"
            ."--{$boundary}--\r\n";

        $response = $this->integration->send(new IntegrationRequest(
            system: PoofConfiguration::PROVIDER,
            operation: 'media.photo_cleanup.remove_background',
            method: 'POST',
            endpoint: rtrim($config['api_base_url'], '/').self::REMOVE_ENDPOINT,
            protocol: 'rest',
            protocolOperation: 'remove_background',
            provider: PoofConfiguration::PROVIDER,
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
            throw PhotoCleanupException::requestFailed(
                PoofConfiguration::PROVIDER_LABEL,
                $response->status,
                $response->exchange?->id,
            );
        }

        return [
            'bytes' => $response->body,
            'provider' => PoofConfiguration::PROVIDER,
            'provider_label' => PoofConfiguration::PROVIDER_LABEL,
        ];
    }
}
