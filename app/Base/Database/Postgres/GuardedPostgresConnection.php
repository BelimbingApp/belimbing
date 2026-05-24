<?php

namespace App\Base\Database\Postgres;

use Illuminate\Database\PostgresConnection;

final class GuardedPostgresConnection extends PostgresConnection
{
    private bool $guardsMigrationIdentifiers = false;

    private bool $migrationIdentifierGuardRegistered = false;

    /**
     * Enable PostgreSQL identifier-length checks only for a migration operation.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function guardMigrationIdentifiers(callable $callback): mixed
    {
        $this->registerMigrationIdentifierGuard();

        $previous = $this->guardsMigrationIdentifiers;
        $this->guardsMigrationIdentifiers = true;

        try {
            return $callback();
        } finally {
            $this->guardsMigrationIdentifiers = $previous;
        }
    }

    private function registerMigrationIdentifierGuard(): void
    {
        if ($this->migrationIdentifierGuardRegistered) {
            return;
        }

        $this->beforeExecuting(function (string $query): void {
            if (! $this->guardsMigrationIdentifiers) {
                return;
            }

            if (PostgresIdentifierGuard::shouldInspectSql($query)) {
                PostgresIdentifierGuard::assertSqlIdentifiersWithinLimit($query);
            }
        });

        $this->migrationIdentifierGuardRegistered = true;
    }
}
