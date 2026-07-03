<?php

namespace App\Base\Media\PhotoCleanup;

final class PhotoRoomSegmentRequest
{
    private const DEFAULT_TIMEOUT_SECONDS = 60;

    private const DEFAULT_RETRY_TIMES = 1;

    private const PROBE_TIMEOUT_SECONDS = 15;

    private const PROBE_RETRY_TIMES = 0;

    public string $imageBytes = '';

    public string $filename = '';

    public string $mimeType = '';

    public string $apiKey = '';

    public string $apiBaseUrl = '';

    public ?int $companyId = null;

    public string $operation = '';

    public ?string $size = null;

    public int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS;

    public int $retryTimes = self::DEFAULT_RETRY_TIMES;

    /**
     * @param  array{api_key: string, api_base_url: string}  $config
     */
    public static function cleanup(string $imageBytes, string $filename, string $mimeType, array $config, ?int $companyId): self
    {
        $request = new self;
        $request->imageBytes = $imageBytes;
        $request->filename = $filename;
        $request->mimeType = $mimeType;
        $request->apiKey = $config['api_key'];
        $request->apiBaseUrl = $config['api_base_url'];
        $request->companyId = $companyId;
        $request->operation = 'media.photo_cleanup.remove_background';

        return $request;
    }

    public static function sandboxProbe(string $imageBytes, string $apiKey, string $apiBaseUrl, ?int $companyId): self
    {
        $request = new self;
        $request->imageBytes = $imageBytes;
        $request->filename = 'photoroom-connection-probe.png';
        $request->mimeType = 'image/png';
        $request->apiKey = $apiKey;
        $request->apiBaseUrl = $apiBaseUrl;
        $request->companyId = $companyId;
        $request->operation = 'media.photo_cleanup.test_connection';
        $request->size = 'preview';
        $request->timeoutSeconds = self::PROBE_TIMEOUT_SECONDS;
        $request->retryTimes = self::PROBE_RETRY_TIMES;

        return $request;
    }
}
