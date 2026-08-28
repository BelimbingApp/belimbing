<?php

namespace App\Base\Settings\Support;

use App\Base\Settings\DTO\Scope;

/**
 * Stable, Audit-neutral subject identity for a setting override.
 */
final class SettingSubject
{
    /** @return array{name: string, id: string} */
    public static function handle(string $key, ?Scope $scope = null): array
    {
        return self::handleStored(
            $key,
            $scope?->type->value,
            $scope?->id,
        );
    }

    /** @return array{name: string, id: string} */
    public static function handleStored(
        string $key,
        ?string $scopeType,
        int|string|null $scopeId,
    ): array {
        return [
            'name' => 'setting',
            'id' => self::storedId($key, $scopeType, $scopeId),
        ];
    }

    public static function id(string $key, ?Scope $scope = null): string
    {
        return self::storedId(
            $key,
            $scope?->type->value,
            $scope?->id,
        );
    }

    public static function storedId(
        string $key,
        ?string $scopeType,
        int|string|null $scopeId,
    ): string {
        if ($scopeType === null && $scopeId === null) {
            return $key;
        }

        return $key.'@'.($scopeType ?? '').':'.($scopeId ?? '');
    }

    /**
     * @param  iterable<int, string>  $keys
     * @return list<array{name: string, id: string}>
     */
    public static function handles(iterable $keys, ?Scope $scope = null): array
    {
        $subjects = [];

        foreach ($keys as $key) {
            $key = trim($key);

            if ($key !== '') {
                $subjects[self::id($key, $scope)] = self::handle($key, $scope);
            }
        }

        return array_values($subjects);
    }
}
