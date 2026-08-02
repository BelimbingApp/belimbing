<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

final class PortableDataShareMirrorSnapshotState
{
    /** @var array<string, int> */
    public array $counts;

    /** @var array<string, \HashContext> */
    public array $hashContexts = [];

    public int $records = 0;

    public int $bytes = 0;

    /** @param list<string> $tables */
    public function __construct(
        array $tables,
        public readonly int $maximumScalarBytes,
        public readonly int $maximumLineBytes,
        public readonly int $maximumSnapshotBytes,
        public readonly string $sourceLabel,
        public readonly ?DataShareMirrorProgress $progress,
    ) {
        $this->counts = array_fill_keys($tables, 0);
        foreach ($tables as $table) {
            $this->hashContexts[$table] = hash_init('sha256');
        }
    }

    /** @return array{counts: array<string, int>, hashes: array<string, string>, records: int, bytes: int} */
    public function result(): array
    {
        $hashes = [];
        foreach ($this->hashContexts as $table => $context) {
            $hashes[$table] = hash_final($context);
        }

        return ['counts' => $this->counts, 'hashes' => $hashes, 'records' => $this->records, 'bytes' => $this->bytes];
    }
}
