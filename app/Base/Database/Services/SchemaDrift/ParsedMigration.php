<?php

namespace App\Base\Database\Services\SchemaDrift;

/**
 * Ordered schema operations from one migration up() path plus constructs the
 * static reader could not resolve without executing application code.
 */
final readonly class ParsedMigration
{
    /**
     * @param  list<TableOperation>  $operations
     * @param  list<array{line: int, reason: string}>  $unreadable
     */
    public function __construct(
        public string $path,
        public string $name,
        public array $operations = [],
        public array $unreadable = [],
    ) {}
}
