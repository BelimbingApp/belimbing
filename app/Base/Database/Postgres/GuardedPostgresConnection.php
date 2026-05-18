<?php

namespace App\Base\Database\Postgres;

use Closure;
use Illuminate\Database\PostgresConnection;

final class GuardedPostgresConnection extends PostgresConnection
{
    protected function run($query, $bindings, Closure $callback)
    {
        if (PostgresIdentifierGuard::shouldInspectSql((string) $query)) {
            PostgresIdentifierGuard::assertSqlIdentifiersWithinLimit((string) $query);
        }

        return parent::run($query, $bindings, $callback);
    }
}
