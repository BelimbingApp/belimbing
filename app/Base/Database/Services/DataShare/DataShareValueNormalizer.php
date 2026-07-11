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

        return match ($this->type($table, $column)) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'decimal' => $this->decimal($value),
            'date' => CarbonImmutable::parse((string) $value, 'UTC')->format('Y-m-d'),
            'datetime' => $this->datetime($value),
            'json' => $this->json($table, $column, $value),
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
