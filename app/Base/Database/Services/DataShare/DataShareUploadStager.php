<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\StagedDataShareUpload;
use App\Base\Database\Exceptions\DataShareTransportException;
use Illuminate\Support\Str;
use Throwable;

class DataShareUploadStager
{
    public function __construct(
        private readonly DataSharePrivateStorage $storage,
        private readonly DataShareSettings $settings,
    ) {}

    /** @param resource $input */
    public function stage(
        $input,
        string $transport,
        ?int $expectedBytes = null,
        ?int $maximumBytes = null,
    ): StagedDataShareUpload {
        $maximum = $this->settings->integer('data_share.transfer_limits.max_package_bytes', 250 * 1024 * 1024, 1, 2147483647);
        $maximum = $maximumBytes === null ? $maximum : min($maximum, $maximumBytes);

        if (! is_resource($input)
            || ($expectedBytes !== null && ($expectedBytes < 1 || $expectedBytes > $maximum))) {
            throw DataShareTransportException::invalidUpload();
        }

        $temporary = tempnam(sys_get_temp_dir(), 'blb-data-share-receive-');

        if ($temporary === false) {
            throw DataShareTransportException::protectedReceiptStorageUnavailable();
        }

        @chmod($temporary, 0600);
        $output = fopen($temporary, 'wb');

        if ($output === false) {
            @unlink($temporary);
            throw DataShareTransportException::invalidUpload();
        }

        $bytes = 0;

        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);

                if ($chunk === false) {
                    throw DataShareTransportException::invalidUpload();
                }

                $bytes += strlen($chunk);

                if ($bytes > $maximum || fwrite($output, $chunk) !== strlen($chunk)) {
                    throw DataShareTransportException::invalidUpload();
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
            throw DataShareTransportException::invalidUpload();
        }

        $path = $this->settings->pathPrefix('data_share.receiving_path_prefix', 'data-share/receiving')
            .'/'.$transport.'/'.Str::lower((string) Str::ulid()).'.upload';
        $stream = fopen($temporary, 'rb');

        try {
            if ($stream === false || ! $this->storage->disk()->put($path, $stream)) {
                throw DataShareTransportException::invalidUpload();
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            @unlink($temporary);
        }

        return new StagedDataShareUpload($path, $bytes);
    }
}
