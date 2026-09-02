<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\Exceptions\DataSharePackageException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use JsonException;

class DataShareValueNormalizer
{
    /** @var array<string, array<string, string>> */
    private array $types = [];

    public function __construct(private readonly DataShareSchemaFingerprint $schemas) {}

    public function encode(string $table, string $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        // PDO pgsql returns bytea as a stream resource; SQLite returns a string.
        // Read it here, once, so the binary rule below sees the same value on
        // every driver and nothing downstream meets a resource.
        if (is_resource($value)) {
            $value = (string) stream_get_contents($value);
        }

        if (is_string($value) && ($this->type($table, $column) === 'binary' || ! mb_check_encoding($value, 'UTF-8'))) {
            return ['__data_share_binary_base64' => base64_encode($value)];
        }

        return $this->normalize($table, $column, $value);
    }

    public function decode(string $table, string $column, mixed $value): mixed
    {
        if (is_array($value) && array_keys($value) === ['__data_share_binary_base64']) {
            $decoded = base64_decode((string) $value['__data_share_binary_base64'], true);

            if ($decoded === false) {
                throw DataSharePackageException::invalidPackage(__('a binary field is not valid Base64.'));
            }

            return $decoded;
        }

        return $this->normalize($table, $column, $value);
    }

    /**
     * Read a fetched row's stream values into strings immediately. PDO pgsql
     * returns bytea as a stream tied to the statement: once the cursor moves
     * on, the handle is closed and the bytes are gone, and a closed resource
     * is not even is_resource() any more. Call this at the fetch boundary.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function materialize(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_resource($value)) {
                $row[$column] = (string) stream_get_contents($value);
            }
        }

        return $row;
    }

    /**
     * Prepare a decoded value for a query-builder write. Binary values become
     * a stream so the connection binds them as PDO::PARAM_LOB: bound as a
     * plain string, PostgreSQL silently truncates a bytea at the first NUL
     * byte and rejects other non-UTF-8 bytes outright.
     */
    public function bindable(string $table, string $column, mixed $value): mixed
    {
        if (! is_string($value) || $this->type($table, $column) !== 'binary') {
            return $value;
        }

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $value);
        rewind($stream);

        return $stream;
    }

    public function type(string $table, string $column): string
    {
        if (! isset($this->types[$table])) {
            $this->types[$table] = [];

            foreach (Schema::getColumns($table) as $definition) {
                $this->types[$table][$definition['name']] = $this->schemas->logicalType((string) $definition['type_name']);
            }
        }

        return $this->types[$table][$column] ?? 'unknown';
    }

    private function normalize(string $table, string $column, mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        $type = $this->type($table, $column);

        return $type === 'json'
            ? $this->json($table, $column, $value)
            : $this->normalizeValue($value, $type);
    }

    public function normalizeValue(mixed $value, string $type): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        return match ($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'decimal' => $this->decimal($value),
            'date' => CarbonImmutable::parse((string) $value, 'UTC')->format('Y-m-d'),
            'datetime' => $this->datetime($value),
            default => $value,
        };
    }

    private function decimal(mixed $value): string
    {
        $decimal = strtolower(trim((string) $value));

        if (str_contains($decimal, 'e')) {
            $decimal = rtrim(rtrim(sprintf('%.15F', (float) $decimal), '0'), '.');
        }

        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '+-');
        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        $normalized = $integer.($fraction === '' ? '' : '.'.$fraction);

        return $negative && $normalized !== '0' ? '-'.$normalized : $normalized;
    }

    private function datetime(mixed $value): string
    {
        $formatted = CarbonImmutable::parse((string) $value, 'UTC')->format('Y-m-d H:i:s.u');

        return str_ends_with($formatted, '.000000') ? substr($formatted, 0, -7) : $formatted;
    }

    private function json(string $table, string $column, mixed $value): string
    {
        if (! is_string($value)) {
            return CanonicalJson::encode($value);
        }

        try {
            return CanonicalJson::encode(json_decode($value, true, flags: JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw DataSharePackageException::invalidPackage(__('database JSON in :table.:column is invalid.', [
                'table' => $table,
                'column' => $column,
            ]));
        }
    }
}
