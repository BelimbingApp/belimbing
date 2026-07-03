<?php

namespace App\Base\Media\PhotoCleanup;

use App\Base\Integration\Services\IntegrationGateway;
use App\Base\Integration\Services\IntegrationRequest;
use App\Base\Integration\Services\IntegrationResponse;
use App\Base\Media\PhotoCleanup\Contracts\ConnectionTestResult;
use App\Base\Media\PhotoCleanup\Contracts\PhotoCleanupProvider;
use App\Base\Media\PhotoCleanup\Contracts\TestsConnection;

/**
 * Thin client for PhotoRoom's Remove Background API
 * (POST /v1/segment). Sandbox and live share the same endpoint; only the
 * resolved API key differs, and sandbox output carries a PhotoRoom watermark.
 *
 * The default {@see PhotoCleanupProvider} implementation. Also implements
 * {@see TestsConnection}: a production key is verified by a cheap
 * `GET /v2/account` read (with a `/v1/account` fallback for accounts on the
 * legacy pricing version) — no image op. A sandbox key (`sandbox_` prefix)
 * has no account state, so PhotoRoom's own response validator rejects both
 * account endpoints; the only honest verification is a minimal probe edit on
 * the same `/v1/segment` endpoint the real cleanup uses. See
 * docs/plans/media-photo-cleanup-providers.md.
 */
class PhotoRoomClient implements PhotoCleanupProvider, TestsConnection
{
    private const SEGMENT_ENDPOINT = '/v1/segment';

    /**
     * Minimal 16×16 RGBA PNG used only for the sandbox connectivity probe —
     * small enough that `size=preview` returns a few hundred bytes. Kept as a
     * base64 constant so the probe carries no file dependency.
     */
    private const PROBE_IMAGE_B64 = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAAGUlEQVR42mNoaGj4TwlmGDVg1IBRA4aLAQCJj38fh9fmyQAAAABJRU5ErkJggg==';

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

        $response = $this->sendSegment(PhotoRoomSegmentRequest::cleanup(
            imageBytes: $imageBytes,
            filename: $filename,
            mimeType: $mimeType,
            config: $config,
            companyId: $companyId,
        ));

        if ($response->failed()) {
            throw PhotoCleanupException::requestFailed(
                PhotoRoomConfiguration::PROVIDER_LABEL,
                $response->status,
                $response->exchange?->id,
            );
        }

        return [
            'bytes' => $response->body,
            'provider' => PhotoRoomConfiguration::PROVIDER,
            'provider_label' => PhotoRoomConfiguration::PROVIDER_LABEL,
        ];
    }

    /**
     * Builds and sends the multipart `POST /v1/segment` request shared by the
     * real cleanup and the sandbox connectivity probe. `size` is sent only
     * when set: the cleanup leaves it unset to use the API default (`full`),
     * the probe pins it to `preview` to keep the watermarked response tiny.
     */
    private function sendSegment(PhotoRoomSegmentRequest $request): IntegrationResponse
    {
        $boundary = 'PhotoRoom'.bin2hex(random_bytes(16));
        $body = "--{$boundary}\r\n"
            ."Content-Disposition: form-data; name=\"image_file\"; filename=\"{$request->filename}\"\r\n"
            ."Content-Type: {$request->mimeType}\r\n\r\n"
            .$request->imageBytes."\r\n";

        if ($request->size !== null) {
            $body .= "--{$boundary}\r\n"
                ."Content-Disposition: form-data; name=\"size\"\r\n\r\n"
                ."{$request->size}\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return $this->integration->send(new IntegrationRequest(
            system: PhotoRoomConfiguration::PROVIDER,
            operation: $request->operation,
            method: 'POST',
            endpoint: rtrim($request->apiBaseUrl, '/').self::SEGMENT_ENDPOINT,
            protocol: 'rest',
            protocolOperation: 'remove_background',
            provider: PhotoRoomConfiguration::PROVIDER,
            headers: [
                'x-api-key' => $request->apiKey,
                'Content-Type' => 'multipart/form-data; boundary='.$boundary,
            ],
            body: $body,
            ownerType: $request->companyId !== null ? 'company' : null,
            ownerId: $request->companyId,
            timeoutSeconds: $request->timeoutSeconds,
            retryTimes: $request->retryTimes,
            asJson: false,
        ));
    }

    public function providerKey(): string
    {
        return PhotoRoomConfiguration::PROVIDER;
    }

    /**
     * Verifies the stored PhotoRoom key. A production key is checked with a
     * cheap `GET /v2/account` read (falling back to `/v1/account` for accounts
     * on the legacy pricing version) — no image is processed. A sandbox key
     * (`sandbox_` prefix) has no account state, so PhotoRoom rejects both
     * account endpoints; the only honest verification is a minimal probe edit
     * on the same `/v1/segment` endpoint the real cleanup uses (watermarked,
     * consumes one sandbox call).
     */
    public function testConnection(?int $companyId = null): ConnectionTestResult
    {
        $config = $this->configuration->resolve($companyId);

        if ($config['api_key'] === null) {
            return ConnectionTestResult::noKeyStored();
        }

        return PhotoRoomConfiguration::isSandboxKey($config['api_key'])
            ? $this->testSandboxConnection($config['api_key'], $config['api_base_url'], $companyId)
            : $this->testAccountConnection($config['api_key'], $companyId);
    }

    /**
     * Sandbox keys have no account/quota state, so both account endpoints 400.
     * Probe the real cleanup endpoint with a tiny image + `size=preview`; a
     * 2xx image response proves the key authenticates and the endpoint works.
     */
    private function testSandboxConnection(string $apiKey, string $apiBaseUrl, ?int $companyId): ConnectionTestResult
    {
        $response = $this->sendSegment(PhotoRoomSegmentRequest::sandboxProbe(
            imageBytes: (string) base64_decode(self::PROBE_IMAGE_B64, true),
            apiKey: $apiKey,
            apiBaseUrl: $apiBaseUrl,
            companyId: $companyId,
        ));

        if ($response->failed()) {
            return in_array($response->status, [401, 403], true)
                ? ConnectionTestResult::unauthorized()
                : ConnectionTestResult::requestFailed($response->status ?? 0);
        }

        return ConnectionTestResult::success(
            (string) __('Sandbox key verified via a probe edit (watermarked; uses sandbox quota).'),
            ['sandbox' => true],
        );
    }

    /**
     * Production-key handshake: read account details. `/v2/account` returns
     * `images` + `plan`; accounts on the older pricing version reject it with
     * a 400, so fall back to `/v1/account` which returns the legacy `credits`
     * shape. No image is processed.
     */
    private function testAccountConnection(string $apiKey, ?int $companyId): ConnectionTestResult
    {
        $response = $this->sendAccountGet(PhotoRoomConfiguration::ACCOUNT_ENDPOINT, $apiKey, $companyId);

        if ($response->failed() && $response->status === 400) {
            $response = $this->sendAccountGet(PhotoRoomConfiguration::ACCOUNT_ENDPOINT_V1, $apiKey, $companyId);
        }

        if ($response->failed()) {
            return in_array($response->status, [401, 403], true)
                ? ConnectionTestResult::unauthorized()
                : ConnectionTestResult::requestFailed($response->status ?? 0);
        }

        $plan = $response->json('plan');
        $available = $response->json('images.available') ?? $response->json('credits.available');
        $subscription = $response->json('images.subscription') ?? $response->json('credits.subscription');

        $context = array_filter([
            'plan' => $plan,
            'available' => $available,
            'subscription' => $subscription,
        ], fn ($value): bool => $value !== null);

        $detail = match (true) {
            is_string($plan) && is_numeric($available) && is_numeric($subscription) => (string) __(
                'Plan: :plan; :available of :subscription credits available.',
                ['plan' => $plan, 'available' => $available, 'subscription' => $subscription],
            ),
            is_string($plan) => (string) __('Plan: :plan.', ['plan' => $plan]),
            is_numeric($available) && is_numeric($subscription) => (string) __(
                ':available of :subscription credits available.',
                ['available' => $available, 'subscription' => $subscription],
            ),
            default => null,
        };

        return ConnectionTestResult::success($detail, $context);
    }

    private function sendAccountGet(string $endpoint, string $apiKey, ?int $companyId): IntegrationResponse
    {
        return $this->integration->send(new IntegrationRequest(
            system: PhotoRoomConfiguration::PROVIDER,
            operation: 'media.photo_cleanup.test_connection',
            method: 'GET',
            endpoint: $endpoint,
            protocol: 'rest',
            protocolOperation: 'account_details',
            provider: PhotoRoomConfiguration::PROVIDER,
            headers: ['x-api-key' => $apiKey],
            ownerType: $companyId !== null ? 'company' : null,
            ownerId: $companyId,
            timeoutSeconds: 15,
            retryTimes: 0,
            asJson: true,
        ));
    }
}
