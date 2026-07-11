<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\Exceptions\DataSharePackageException;

class DataShareSchemaCompatibility
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
            throw DataSharePackageException::schemaMismatch($table);
        }

        $sourceColumns = $source['columns'] ?? [];
        $destinationColumns = $destination['columns'] ?? [];

        if (count($sourceColumns) !== count($destinationColumns)) {
            throw DataSharePackageException::schemaMismatch($table);
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
                throw DataSharePackageException::schemaMismatch($table);
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
