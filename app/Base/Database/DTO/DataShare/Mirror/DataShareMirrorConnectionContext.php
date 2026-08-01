<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

final readonly class DataShareMirrorConnectionContext
{
    public function __construct(
        public bool $configured,
        public bool $reachable,
        public ?string $driver = null,
        public ?string $localRole = null,
        public ?string $remoteRole = null,
        public ?string $serverVersion = null,
        public ?string $pgDumpVersion = null,
        public ?string $psqlVersion = null,
        public ?string $localDriver = null,
    ) {}
}
