<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\StagedDataShareUpload;
use App\Base\Database\Exceptions\DataShareTransportException;
use Illuminate\Support\Str;

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
        $maximum = $this->maximumBytes($maximumBytes);
        $this->assertValidInput($input, $expectedBytes, $maximum);

        $temporary = tempnam(sys_get_temp_dir(), 'blb-data-share-receive-');

        if ($temporary === false) {
            throw DataShareTransportException::protectedReceiptStorageUnavailable();
        }

        try {
            $bytes = $this->writeTemporaryUpload($input, $temporary, $maximum);
            $this->assertExpectedBytes($bytes, $expectedBytes);

            return new StagedDataShareUpload($this->storeTemporaryUpload($temporary, $transport), $bytes);
        } finally {
            @unlink($temporary);
        }
    }

    private function maximumBytes(?int $maximumBytes): int
    {
        $configured = $this->settings->integer('data_share.transfer_limits.max_package_bytes', 250 * 1024 * 1024, 1, 2147483647);

        return $maximumBytes === null ? $configured : min($configured, $maximumBytes);
    }

    private function assertValidInput(mixed $input, ?int $expectedBytes, int $maximum): void
    {
        if (! is_resource($input)
            || ($expectedBytes !== null && ($expectedBytes < 1 || $expectedBytes > $maximum))) {
            throw DataShareTransportException::invalidUpload();
        }
    }

    private function writeTemporaryUpload(mixed $input, string $temporary, int $maximum): int
    {
        @chmod($temporary, 0600);
        $output = fopen($temporary, 'wb');

        if ($output === false) {
            throw DataShareTransportException::invalidUpload();
        }

        try {
            return $this->copyUploadToTemporaryFile($input, $output, $maximum);
        } finally {
            fclose($output);
        }
    }

    private function copyUploadToTemporaryFile(mixed $input, mixed $output, int $maximum): int
    {
        $bytes = 0;

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

        return $bytes;
    }

    private function assertExpectedBytes(int $bytes, ?int $expectedBytes): void
    {
        if ($bytes < 1 || ($expectedBytes !== null && $bytes !== $expectedBytes)) {
            throw DataShareTransportException::invalidUpload();
        }
    }

    private function storeTemporaryUpload(string $temporary, string $transport): string
    {
        $path = $this->settings->pathPrefix('data_share.receiving_path_prefix', 'data-share/receiving')
            .'/'.$transport.'/'.Str::lower((string) Str::ulid()).'.upload';
        $stream = fopen($temporary, 'rb');

        try {
            if ($stream === false || ! $this->storage->disk()->put($path, $stream)) {
                throw DataShareTransportException::invalidUpload();
            }

            return $path;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
