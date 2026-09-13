<?php

namespace App\Base\Audit\Services;

use App\Base\Authz\Enums\PrincipalType;
use Illuminate\Database\Eloquent\Builder;

final class AuditSearchSql
{
    private const LIKE_PLACEHOLDER = ' like ?';

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function withActorName(Builder $query, string $table): Builder
    {
        return $query
            ->leftJoin('users', function ($join) use ($table): void {
                $join->on($table.'.actor_id', '=', 'users.id')
                    ->where($table.'.actor_type', '=', PrincipalType::USER->value);
            })
            ->select($table.'.*', 'users.name as actor_name');
    }

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
        return 'lower('.$this->textCastExpression($column).')';
    }

    public function lowerCoalescedExpression(string $column): string
    {
        return 'lower(coalesce('.$column.', \'\'))';
    }

    public function lowerCoalescedLikeExpression(string $column): string
    {
        return $this->lowerCoalescedExpression($column).self::LIKE_PLACEHOLDER;
    }

    public function jsonTextExpression(string $column): string
    {
        return match (config('database.default')) {
            'pgsql' => $column.'::text',
            'mysql', 'mariadb' => $this->textCastExpression($column),
            default => $column,
        };
    }

    public function ipAddressTextExpression(string $column): string
    {
        return $this->textCastExpression($column);
    }

    public function jsonIntegerExpression(string $column, string $key): string
    {
        return match (config('database.default')) {
            'pgsql' => "nullif({$column}->>'{$key}', '')::int",
            'mysql', 'mariadb' => "cast(json_unquote(json_extract({$column}, '$.{$key}')) as signed)",
            default => "cast(json_extract({$column}, '$.{$key}') as integer)",
        };
    }

    private function textCastExpression(string $column): string
    {
        $type = match (config('database.default')) {
            'mysql', 'mariadb' => 'char',
            default => 'text',
        };

        return 'cast('.$column.' as '.$type.')';
    }
}
