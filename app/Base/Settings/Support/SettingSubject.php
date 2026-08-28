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
        return [
            'name' => 'setting',
            'id' => self::id($key, $scope),
        ];
    }

    public static function id(string $key, ?Scope $scope = null): string
    {
        if ($scope === null) {
            return $key;
        }

        return $key.'@'.$scope->type->value.':'.$scope->id;
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
