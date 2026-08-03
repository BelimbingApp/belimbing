<?php

namespace App\Base\Database\Services\SchemaDrift;

final readonly class SchemaDriftReport
{
    /**
     * @param  list<SchemaDriftFinding>  $findings
     * @param  list<array{migration: string, line: int, reason: string}>  $unreadable
     */
    public function __construct(
        public string $connection,
        public string $driver,
        public string $database,
        public int $migrationCount,
        public int $tableCount,
        public array $findings,
        public array $unreadable,
    ) {}
}
