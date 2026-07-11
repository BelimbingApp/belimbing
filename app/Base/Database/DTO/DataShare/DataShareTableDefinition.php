<?php

namespace App\Base\Database\DTO\DataShare;

final readonly class DataShareTableDefinition
{
    /**
     * @param  list<string>  $primaryKeyColumns
     * @param  list<DataShareReferenceDefinition>  $references
     */
    public function __construct(
        public string $table,
        public array $primaryKeyColumns,
        public array $references,
    ) {}
}
