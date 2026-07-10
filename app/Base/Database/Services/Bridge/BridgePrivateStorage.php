<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\Exceptions\BridgePackageException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;

class BridgePrivateStorage
{
    public function __construct(
        private readonly FilesystemManager $disks,
        private readonly BridgeSettings $settings,
    ) {}

    public function disk(): Filesystem
    {
        $diskName = $this->settings->disk();
        $diskConfig = config("filesystems.disks.{$diskName}", []);

        if ($diskName === 'public' || ($diskConfig['visibility'] ?? null) === 'public') {
            throw BridgePackageException::unsafeDisk($diskName);
        }

        return $this->disks->disk($diskName);
    }

    public function outgoingPath(string $packageId): string
    {
        return $this->settings->pathPrefix('bridge.outgoing_path_prefix', 'bridge/outgoing')
            .'/'.$packageId.'.bridge.ndjson';
    }

    public function incomingPath(string $packageId): string
    {
        return $this->settings->pathPrefix('bridge.incoming_path_prefix', 'bridge/incoming')
            .'/'.$packageId.'.bridge.ndjson';
    }
}
