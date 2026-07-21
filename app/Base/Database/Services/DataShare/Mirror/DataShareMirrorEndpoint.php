<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use Illuminate\Database\Connection;

final readonly class DataShareMirrorEndpoint
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        public string $label,
        public Connection $connection,
        public array $configuration,
        public string $driver,
    ) {}
}
