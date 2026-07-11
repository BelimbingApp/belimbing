<?php

namespace App\Base\Database\DTO\DataShare;

final readonly class DataShareScopeDefinition
{
    /** @param list<DataShareTableDefinition> $tables */
    public function __construct(
        public string $name,
        public string $label,
        public string $modulePath,
        public array $tables,
    ) {}
}
