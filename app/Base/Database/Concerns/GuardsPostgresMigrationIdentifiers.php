<?php

namespace App\Base\Database\Concerns;

use App\Base\Database\Postgres\GuardedPostgresConnection;
use Illuminate\Support\Facades\DB;

trait GuardsPostgresMigrationIdentifiers
{
    /**
     * Run a migration operation with PostgreSQL schema identifier checks enabled.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function guardPostgresMigrationIdentifiers(?string $database, callable $callback): mixed
    {
        $connection = DB::connection($database);

        if (! $connection instanceof GuardedPostgresConnection) {
            return $callback();
        }

        return $connection->guardMigrationIdentifiers($callback);
    }
}
