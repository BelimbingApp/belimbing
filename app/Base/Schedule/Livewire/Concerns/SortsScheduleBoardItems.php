<?php

namespace App\Base\Schedule\Livewire\Concerns;

trait SortsScheduleBoardItems
{
    /**
     * @template T
     *
     * @param  list<T>  $items
     * @param  callable(T, string): mixed  $value
     * @return list<T>
     */
    private function sortItems(array $items, string $column, string $direction, callable $value): array
    {
        usort($items, function (mixed $a, mixed $b) use ($column, $direction, $value): int {
            $left = $value($a, $column);
            $right = $value($b, $column);
            $comparison = $this->compareNullableValues($left, $right);

            if ($comparison === 0) {
                return 0;
            }

            return $direction === 'desc' ? -$comparison : $comparison;
        });

        return $items;
    }

    private function compareNullableValues(mixed $left, mixed $right): int
    {
        return match (true) {
            $left === null && $right === null => 0,
            $left === null => 1,
            $right === null => -1,
            default => $this->compareValues($left, $right),
        };
    }

    private function compareValues(mixed $left, mixed $right): int
    {
        if (is_string($left) || is_string($right)) {
            return strnatcasecmp((string) $left, (string) $right);
        }

        return $left <=> $right;
    }
}
