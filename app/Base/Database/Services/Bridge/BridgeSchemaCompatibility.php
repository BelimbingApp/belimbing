<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\Exceptions\BridgePackageException;

class BridgeSchemaCompatibility
{
    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $destination
     */
    public function assertCompatible(array $source, array $destination, string $sourceDriver, string $destinationDriver): void
    {
        $table = (string) ($source['table'] ?? 'unknown');

        if (($source['table'] ?? null) !== ($destination['table'] ?? null)
            || ($source['primary_key'] ?? null) !== ($destination['primary_key'] ?? null)
            || CanonicalJson::encode($source['unique_indexes'] ?? null) !== CanonicalJson::encode($destination['unique_indexes'] ?? null)
            || CanonicalJson::encode($source['foreign_keys'] ?? null) !== CanonicalJson::encode($destination['foreign_keys'] ?? null)) {
            throw BridgePackageException::schemaMismatch($table);
        }

        $sourceColumns = $source['columns'] ?? [];
        $destinationColumns = $destination['columns'] ?? [];

        if (count($sourceColumns) !== count($destinationColumns)) {
            throw BridgePackageException::schemaMismatch($table);
        }

        foreach ($sourceColumns as $index => $sourceColumn) {
            $destinationColumn = $destinationColumns[$index] ?? [];

            if (($sourceColumn['name'] ?? null) !== ($destinationColumn['name'] ?? null)
                || ($sourceColumn['nullable'] ?? null) !== ($destinationColumn['nullable'] ?? null)
                || ! $this->typesCompatible(
                    (string) ($sourceColumn['type'] ?? ''),
                    (string) ($destinationColumn['type'] ?? ''),
                    $sourceDriver,
                    $destinationDriver,
                )) {
                throw BridgePackageException::schemaMismatch($table);
            }
        }
    }

    private function typesCompatible(string $source, string $destination, string $sourceDriver, string $destinationDriver): bool
    {
        if ($source === $destination) {
            return true;
        }

        $sqliteCrossDriver = $sourceDriver === 'sqlite' xor $destinationDriver === 'sqlite';

        return $sqliteCrossDriver && in_array([$source, $destination], [
            ['text', 'json'],
            ['json', 'text'],
            ['string', 'text'],
            ['text', 'string'],
        ], true);
    }
}
