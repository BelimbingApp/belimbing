<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Exceptions\DataShareMirrorException;
use Illuminate\Filesystem\Filesystem;

final readonly class DataShareMirrorTemporaryFiles
{
    public function __construct(private Filesystem $files) {}

    public function create(string $prefix, string $suffix = ''): string
    {
        $path = tempnam($this->directory(), $prefix);
        if ($path === false) {
            throw DataShareMirrorException::safeFailure(__('Mirror temporary storage could not be prepared.'));
        }

        if ($suffix !== '') {
            $renamed = $path.$suffix;
            if (! rename($path, $renamed)) {
                @unlink($path);
                throw DataShareMirrorException::safeFailure(__('Mirror temporary storage could not be prepared.'));
            }
            $path = $renamed;
        }

        @chmod($path, 0600);

        return $path;
    }

    public function directory(): string
    {
        $configured = config('data_share.mirror.temp_path', storage_path('app/private/data-share/mirror'));
        $directory = is_string($configured) && trim($configured) !== ''
            ? $configured
            : storage_path('app/private/data-share/mirror');

        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0700, true);
        }

        $resolvedDirectory = realpath($directory);
        $resolvedPublic = realpath(public_path());
        if ($resolvedDirectory === false
            || ($resolvedPublic !== false && str_starts_with(
                mb_strtolower(str_replace('\\', '/', $resolvedDirectory)).'/',
                mb_strtolower(str_replace('\\', '/', $resolvedPublic)).'/',
            ))) {
            throw DataShareMirrorException::unavailable(__('The mirror temporary directory must be a private filesystem location.'));
        }

        @chmod($directory, 0700);

        return $resolvedDirectory;
    }
}
