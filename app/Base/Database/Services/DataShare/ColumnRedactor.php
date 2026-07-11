<?php

namespace App\Base\Database\Services\DataShare;

/**
 * Removes secret-bearing column values from captured rows.
 *
 * A column is redacted for the whole table when its name matches a secret
 * pattern or any of its values looks like Laravel encrypted-cast ciphertext.
 * Ciphertext is bound to the source APP_KEY and is both useless and unsafe
 * to move between instances.
 */
class ColumnRedactor
{
    private const SECRET_NAME_PATTERN = '/password|secret|token|api[_-]?key|private[_-]?key|credential|passphrase/i';

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, redacted_columns: list<string>}
     */
    public function redact(string $table, array $rows): array
    {
        $columns = array_keys($this->redactedColumnMap($table, $rows));
        sort($columns, SORT_STRING);

        $rows = array_map(function (array $row) use ($columns): array {
            foreach ($columns as $column) {
                if (array_key_exists($column, $row)) {
                    $row[$column] = null;
                }
            }

            return $row;
        }, $rows);

        return ['rows' => $rows, 'redacted_columns' => $columns];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, true>
     */
    private function redactedColumnMap(string $table, array $rows): array
    {
        $redacted = $this->configuredRedactedColumnMap($table);

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                if (! isset($redacted[$column]) && $this->shouldRedactColumn($column, $value)) {
                    $redacted[$column] = true;
                }
            }
        }

        return $redacted;
    }

    /** @return array<string, true> */
    private function configuredRedactedColumnMap(string $table): array
    {
        $rules = config('data_share.redaction.columns', []);
        $columns = is_array($rules) && is_array($rules[$table] ?? null)
            ? array_values(array_filter($rules[$table], is_string(...)))
            : [];

        return array_fill_keys($columns, true);
    }

    private function shouldRedactColumn(string $column, mixed $value): bool
    {
        return preg_match(self::SECRET_NAME_PATTERN, $column) === 1
            || $this->looksLikeLaravelCiphertext($value);
    }

    private function looksLikeLaravelCiphertext(mixed $value, int $depth = 0): bool
    {
        if (! is_string($value) || strlen($value) < 80 || $depth > 1) {
            return false;
        }

        // JSON columns store encrypted strings with a surrounding JSON quote.
        // Unwrap that representation before inspecting Laravel's envelope.
        $jsonValue = json_decode($value, true);

        if (is_string($jsonValue) && $jsonValue !== $value) {
            return $this->looksLikeLaravelCiphertext($jsonValue, $depth + 1);
        }

        return $this->isLaravelCiphertextEnvelope($value);
    }

    private function isLaravelCiphertextEnvelope(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $json = json_decode($decoded, true);

        return is_array($json)
            && isset($json['iv'], $json['value'])
            && (array_key_exists('mac', $json) || array_key_exists('tag', $json));
    }
}
