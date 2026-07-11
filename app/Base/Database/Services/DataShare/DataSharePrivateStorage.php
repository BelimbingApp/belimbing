<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\Exceptions\DataSharePackageException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;

class DataSharePrivateStorage
{
    public function __construct(
        private readonly FilesystemManager $disks,
        private readonly DataShareSettings $settings,
    ) {}

    public function disk(): Filesystem
    {
        $diskName = $this->settings->disk();
        $diskConfig = config("filesystems.disks.{$diskName}", []);

        if ($diskName === 'public' || ($diskConfig['visibility'] ?? null) === 'public') {
            throw DataSharePackageException::unsafeDisk($diskName);
        }

        return $this->disks->disk($diskName);
    }

    public function outgoingPath(string $packageId): string
    {
        return $this->settings->pathPrefix('data_share.outgoing_path_prefix', 'data-share/outgoing')
            .'/'.$packageId.'.data_share.ndjson';
    }

    public function incomingPath(string $packageId): string
    {
        return $this->settings->pathPrefix('data_share.incoming_path_prefix', 'data-share/incoming')
            .'/'.$packageId.'.data_share.ndjson';
    }
}
