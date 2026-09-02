<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Services\DataShare\CanonicalJson;
use App\Base\Database\Services\DataShare\DataShareValueNormalizer;
use Illuminate\Database\Connection;
use JsonException;

class PortableDataShareMirrorValueCodec
{
    public function __construct(
        private readonly DataShareMirrorSchemaComparator $schemas,
        private readonly DataShareValueNormalizer $values,
    ) {}

    /** @return array<string, string> */
    public function columnTypes(Connection $connection, string $table): array
    {
        $types = [];
        foreach ($connection->getSchemaBuilder()->getColumns($table) as $column) {
            $types[(string) $column['name']] = mb_strtolower((string) ($column['type'] ?? $column['type_name'] ?? ''));
        }

        return $types;
    }

    public function encode(mixed $value, string $targetType): mixed
    {
        if ($value === null) {
            return null;
        }

        // Read a bytea stream before the string checks, or the binary branch
        // never fires on PostgreSQL and a resource reaches CanonicalJson.
        $value = DataShareValueNormalizer::bytes($value);
        $type = $this->schemas->portableType($targetType);
        if (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || $type === 'binary')) {
            return ['__data_share_binary_base64' => base64_encode($value)];
        }

        if ($type === 'textual' && str_contains($targetType, 'json')) {
            return $this->json($value);
        }

        return $this->values->normalizeValue($value, $type);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function decodeRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if (! is_array($value)
                || count($value) !== 1
                || ! array_key_exists('__data_share_binary_base64', $value)) {
                continue;
            }

            $decoded = base64_decode((string) $value['__data_share_binary_base64'], true);
            if ($decoded === false) {
                throw DataShareMirrorException::safeFailure(__('The temporary staging file contains invalid binary data.'));
            }

            // The target is always PostgreSQL: bind as a stream or the bytes
            // are truncated at the first NUL on insert.
            $row[$column] = DataShareValueNormalizer::stream($decoded);
        }

        return $row;
    }

    private function json(mixed $value): string
    {
        try {
            return CanonicalJson::encode(is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value);
        } catch (JsonException) {
            throw DataShareMirrorException::invalidSelection(__('A selected table contains invalid JSON data.'));
        }
    }
}
