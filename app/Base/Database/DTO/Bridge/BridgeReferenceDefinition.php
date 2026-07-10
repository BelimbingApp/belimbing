<?php

namespace App\Base\Database\DTO\Bridge;

final readonly class BridgeReferenceDefinition
{
    /**
     * @param  list<string>  $localColumns
     * @param  list<string>  $targetColumns
     */
    public function __construct(
        public array $localColumns,
        public string $targetTable,
        public array $targetColumns,
        public bool $nullable,
    ) {}
}
