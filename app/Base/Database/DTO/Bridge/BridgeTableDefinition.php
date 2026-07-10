<?php

namespace App\Base\Database\DTO\Bridge;

final readonly class BridgeTableDefinition
{
    /**
     * @param  list<string>  $primaryKeyColumns
     * @param  list<BridgeReferenceDefinition>  $references
     */
    public function __construct(
        public string $table,
        public array $primaryKeyColumns,
        public array $references,
    ) {}
}
