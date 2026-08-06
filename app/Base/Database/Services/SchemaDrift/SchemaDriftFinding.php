<?php

namespace App\Base\Database\Services\SchemaDrift;

final readonly class SchemaDriftFinding
{
    public function __construct(
        public SchemaDriftFindingKind $kind,
        public string $table,
        public string $object,
        public string $migration,
        public int $line,
    ) {}
}
