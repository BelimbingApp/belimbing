<?php

namespace App\Base\Database\DTO\Bridge;

final readonly class BridgeScopeDefinition
{
    /** @param list<BridgeTableDefinition> $tables */
    public function __construct(
        public string $name,
        public string $label,
        public string $modulePath,
        public array $tables,
    ) {}
}
