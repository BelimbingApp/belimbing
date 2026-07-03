<?php

namespace App\Base\Settings\Support;

final class SettingsFieldValue
{
    public static function formKey(string $settingKey): string
    {
        return str_replace('.', '__', $settingKey);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>|null  $allowed
     * @return list<string>
     */
    public static function checkboxList(mixed $value, array $field, ?array $allowed = null): array
    {
        $allowed ??= array_keys($field['options'] ?? []);
        $items = is_string($value)
            ? preg_split('/[\s,]+/', trim($value))
            : (array) $value;

        return collect($items ?: [])
            ->filter(fn (mixed $item): bool => is_scalar($item))
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '' && ($allowed === [] || in_array($item, $allowed, true)))
            ->values()
            ->all();
    }
}
