<?php

namespace App\Base\Audit\Services;

final class AuditSearchSql
{
    /** @return array{name: string, id: string}|null */
    public function parseSubjectHandle(string $search): ?array
    {
        if (! str_contains($search, '#')) {
            return null;
        }

        [$name, $id] = array_pad(explode('#', $search, 2), 2, '');
        $name = strtolower(trim($name));
        $id = trim($id);

        if ($name === '' || $id === '') {
            return null;
        }

        return ['name' => $name, 'id' => $id];
    }

    public function lowerTextExpression(string $column): string
    {
        return match (config('database.default')) {
            'mysql', 'mariadb' => 'lower(cast('.$column.' as char))',
            default => 'lower(cast('.$column.' as text))',
        };
    }

    public function lowerCoalescedExpression(string $column): string
    {
        return 'lower(coalesce('.$column.', \'\'))';
    }
}
