<?php

namespace App\Base\Database\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class IncubatingSchemaTableDropper
{
    /**
     * @param  list<string>  $tables
     * @return array<string, list<array<string, mixed>>>
     */
    public function foreignKeysByTable(array $tables): array
    {
        $map = [];

        foreach ($tables as $table) {
            $map[$table] = Schema::getForeignKeys($table);
        }

        return $map;
    }

    /**
     * Drop tables in a driver-agnostic order.
     *
     * Tables are dropped dependents-first, satisfying PostgreSQL/MySQL
     * foreign-key enforcement and SQLite's pragma without disabling checks.
     * Remaining cycles are dropped together with driver-specific handling.
     *
     * On SQLite, user triggers are dropped first. Triggers may call
     * connection-local PHP functions (`sqliteCreateFunction`) that do not
     * survive process restart; evaluating them during DROP TABLE then fails
     * with "no such function" and blocks incubating rebuilds.
     *
     * @param  list<string>  $tables
     * @param  array<string, list<array<string, mixed>>>  $foreignKeysByTable
     */
    public function drop(array $tables, array $foreignKeysByTable): void
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            $this->dropSqliteTriggers($tables);
        }

        [$ordered, $cyclic] = $this->topologicalDropOrder($tables, $foreignKeysByTable);

        foreach ($ordered as $table) {
            Schema::dropIfExists($table);
        }

        if ($cyclic === []) {
            return;
        }

        if ($connection->getDriverName() === 'sqlite') {
            $deferForeignKeys = $connection->transactionLevel() > 0;

            if ($deferForeignKeys) {
                $connection->statement('PRAGMA defer_foreign_keys = ON');
            }

            Schema::disableForeignKeyConstraints();

            try {
                foreach ($cyclic as $table) {
                    Schema::dropIfExists($table);
                }
            } catch (\Throwable $exception) {
                Schema::enableForeignKeyConstraints();

                throw $exception;
            }

            Schema::enableForeignKeyConstraints();

            if ($deferForeignKeys) {
                $connection->statement('PRAGMA defer_foreign_keys = OFF');
            }

            return;
        }

        $grammar = $connection->getQueryGrammar();
        $wrapped = array_map(fn (string $table): string => $grammar->wrapTable($table), $cyclic);
        $connection->statement('DROP TABLE IF EXISTS '.implode(', ', $wrapped));
    }

    /**
     * @param  list<string>  $tables
     */
    private function dropSqliteTriggers(array $tables): void
    {
        if ($tables === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $triggers = DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name IN ({$placeholders})",
            $tables,
        );

        foreach ($triggers as $trigger) {
            $name = is_object($trigger) ? ($trigger->name ?? null) : null;

            if (! is_string($name) || $name === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                continue;
            }

            DB::statement('DROP TRIGGER IF EXISTS '.$name);
        }
    }

    /**
     * Order $tables so each table appears before tables it references.
     *
     * @param  list<string>  $tables
     * @param  array<string, list<array<string, mixed>>>  $foreignKeysByTable
     * @return array{0: list<string>, 1: list<string>}
     */
    private function topologicalDropOrder(array $tables, array $foreignKeysByTable): array
    {
        $tableSet = array_fill_keys($tables, true);
        $outbound = [];
        $inDegree = [];

        foreach ($tables as $table) {
            $outbound[$table] = [];
            $inDegree[$table] = 0;
        }

        foreach ($tables as $table) {
            foreach ($foreignKeysByTable[$table] ?? [] as $foreignKey) {
                $parent = $foreignKey['foreign_table'] ?? null;

                if (! is_string($parent) || $parent === $table || ! isset($tableSet[$parent])) {
                    continue;
                }

                $outbound[$table][] = $parent;
                $inDegree[$parent]++;
            }
        }

        $queue = array_values(array_filter($tables, fn (string $table): bool => $inDegree[$table] === 0));
        sort($queue);

        $ordered = [];

        while ($queue !== []) {
            $table = array_shift($queue);
            $ordered[] = $table;

            foreach ($outbound[$table] as $parent) {
                if (--$inDegree[$parent] === 0) {
                    $queue[] = $parent;
                    sort($queue);
                }
            }
        }

        $cyclic = array_values(array_filter(
            $tables,
            fn (string $table): bool => $inDegree[$table] > 0,
        ));

        return [$ordered, $cyclic];
    }
}
