<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\DTO\Bridge\StagedBridgeUpload;
use App\Base\Database\Exceptions\BridgeTransportException;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class BridgeUploadStager
{
    public function __construct(
        private readonly BridgePrivateStorage $storage,
        private readonly BridgeSettings $settings,
    ) {}

    /** @param resource $input */
    public function stage(
        $input,
        string $transport,
        ?int $expectedBytes = null,
        ?int $maximumBytes = null,
    ): StagedBridgeUpload {
        $maximum = $this->settings->integer('bridge.transfer_limits.max_package_bytes', 250 * 1024 * 1024, 1, 2147483647);
        $maximum = $maximumBytes === null ? $maximum : min($maximum, $maximumBytes);

        if (! is_resource($input)
            || ($expectedBytes !== null && ($expectedBytes < 1 || $expectedBytes > $maximum))) {
            throw BridgeTransportException::invalidUpload();
        }

        $temporary = tempnam(sys_get_temp_dir(), 'blb-bridge-receive-');

        if ($temporary === false) {
            throw new RuntimeException(__('Could not allocate protected receipt storage.'));
        }

        @chmod($temporary, 0600);
        $output = fopen($temporary, 'wb');

        if ($output === false) {
            @unlink($temporary);
            throw BridgeTransportException::invalidUpload();
        }

        $bytes = 0;

        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);

                if ($chunk === false) {
                    throw BridgeTransportException::invalidUpload();
                }

                $bytes += strlen($chunk);

                if ($bytes > $maximum || fwrite($output, $chunk) !== strlen($chunk)) {
                    throw BridgeTransportException::invalidUpload();
                }
            }
        } catch (Throwable $e) {
            fclose($output);
            @unlink($temporary);
            throw $e;
        }

        fclose($output);

        if ($bytes < 1 || ($expectedBytes !== null && $bytes !== $expectedBytes)) {
            @unlink($temporary);
            throw BridgeTransportException::invalidUpload();
        }

        $path = $this->settings->pathPrefix('bridge.receiving_path_prefix', 'bridge/receiving')
            .'/'.$transport.'/'.Str::lower((string) Str::ulid()).'.upload';
        $stream = fopen($temporary, 'rb');

        try {
            if ($stream === false || ! $this->storage->disk()->put($path, $stream)) {
                throw BridgeTransportException::invalidUpload();
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            @unlink($temporary);
        }

        return new StagedBridgeUpload($path, $bytes);
    }
}
