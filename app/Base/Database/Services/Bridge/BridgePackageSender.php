<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\DTO\Bridge\BridgeExportResult;
use App\Base\Database\DTO\Bridge\BridgeReceiveGrantBundle;
use App\Base\Database\Exceptions\BridgeTransportException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class BridgePackageSender
{
    public function __construct(
        private readonly BridgePrivateStorage $storage,
        private readonly BridgeSettings $settings,
    ) {}

    /** @return array{package_id: string, sha256: string, status: string, grant_id: string} */
    public function send(BridgeExportResult $export, BridgeReceiveGrantBundle $grant): array
    {
        if (($export->manifest['receive_grant_id'] ?? null) !== $grant->grantId
            || ($export->manifest['target']['id'] ?? null) !== $grant->target->id
            || $export->bytes > $grant->maxBytes) {
            throw BridgeTransportException::sendFailed(__('the exported package does not match the receive key.'));
        }

        $stream = $this->storage->disk()->readStream($export->path);

        if ($stream === false) {
            throw BridgeTransportException::sendFailed(__('the protected Outgoing package cannot be read.'));
        }

        try {
            $response = Http::acceptJson()
                ->withToken($grant->secret)
                ->withHeaders([
                    'Content-Length' => (string) $export->bytes,
                    'X-Data-Bridge-Package-Id' => $export->packageId,
                    'X-Data-Bridge-Package-Sha256' => $export->sha256,
                ])
                ->withBody(Utils::streamFor($stream), 'application/x-ndjson')
                ->connectTimeout(15)
                ->timeout($this->settings->integer('bridge.receive_grants.send_timeout_seconds', 600, 30, 7200))
                ->post($grant->endpoint);
        } catch (ConnectionException $e) {
            throw BridgeTransportException::sendFailed($e->getMessage());
        } finally {
            fclose($stream);
        }

        $payload = $response->json();

        if ($response->status() !== 202
            || ! is_array($payload)
            || ($payload['package_id'] ?? null) !== $export->packageId
            || ($payload['sha256'] ?? null) !== $export->sha256
            || ($payload['grant_id'] ?? null) !== $grant->grantId
            || ($payload['status'] ?? null) !== 'verified') {
            throw BridgeTransportException::sendFailed(mb_substr(
                (string) ($payload['message'] ?? __('unexpected response status :status.', ['status' => $response->status()])),
                0,
                500,
            ));
        }

        return [
            'package_id' => $payload['package_id'],
            'sha256' => $payload['sha256'],
            'status' => $payload['status'],
            'grant_id' => $payload['grant_id'],
        ];
    }
}
