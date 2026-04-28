<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Services;

use App\Base\Database\Exceptions\BlbQueryException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Execute user-defined SQL queries in a safe, read-only manner.
 *
 * Uses a dedicated read-only database connection and enforces guardrails
 * including SELECT-only validation, row limits, and query timeouts.
 */
class QueryExecutor
{
    /**
     * Maximum number of rows that can be returned.
     */
    private const MAX_ROWS = 1000;

    /**
     * Default query timeout in seconds.
     */
    private const DEFAULT_TIMEOUT_SECONDS = 10;

    /**
     * The read-only database connection name.
     */
    private const CONNECTION = 'readonly';

    /**
     * Forbidden SQL keywords that indicate write operations.
     *
     * @var list<string>
     */
    private const FORBIDDEN_KEYWORDS = [
        'INSERT',
        'UPDATE',
        'DELETE',
        'DROP',
        'ALTER',
        'CREATE',
        'TRUNCATE',
        'REPLACE',
        'GRANT',
        'REVOKE',
        'LOCK',
        'UNLOCK',
    ];

    /**
     * Execute a read-only SQL query with pagination.
     *
     * @param  string  $sql  The SELECT query to execute
     * @param  int  $page  Current page number (1-based)
     * @param  int  $perPage  Rows per page (max 1000)
     * @param  string|null  $orderBy  When set, must be a column name returned by the query (validated before use)
     * @param  string  $orderDir  asc or desc
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, total: int, per_page: int, current_page: int, last_page: int}
     *
     * @throws BlbQueryException
     */
    public function execute(string $sql, int $page = 1, int $perPage = 25, ?string $orderBy = null, string $orderDir = 'asc'): array
    {
        $this->validate($sql);

        $perPage = min(max($perPage, 1), self::MAX_ROWS);
        $page = max($page, 1);

        $connection = DB::connection(self::CONNECTION);

        try {
            return $connection->transaction(function () use ($connection, $sql, $page, $perPage, $orderBy, $orderDir): array {
                $this->applyReadOnly($connection);
                $this->applyTimeout($connection);

                $columnsWhitelist = $this->extractColumnsFromEmpty($connection, $sql);
                $orderClause = '';
                if ($orderBy !== null && $orderBy !== '' && in_array($orderBy, $columnsWhitelist, true)) {
                    $dir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';
                    $quoted = $connection->getQueryGrammar()->wrap($orderBy);
                    $orderClause = ' ORDER BY '.$quoted.' '.$dir;
                }

                $total = $this->countResults($connection, $sql);

                $offset = ($page - 1) * $perPage;
                $paginatedSql = "SELECT * FROM ({$sql}) AS __blb_view{$orderClause} LIMIT {$perPage} OFFSET {$offset}";
                $rows = $connection->select($paginatedSql);

                $rows = array_map(fn (object $row): array => (array) $row, $rows);

                $columns = $rows !== [] ? array_keys($rows[0]) : $this->extractColumnsFromEmpty($connection, $sql);

                $lastPage = max((int) ceil($total / $perPage), 1);

                return [
                    'columns' => array_values($columns),
                    'rows' => array_values($rows),
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                ];
            });
        } catch (BlbQueryException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw BlbQueryException::executionFailed($e->getMessage(), $e);
        }
    }

    /**
     * Validate that a SQL string is a safe SELECT query.
     *
     * @param  string  $sql  The SQL string to validate
     *
     * @throws BlbQueryException
     */
    public function validate(string $sql): void
    {
        $trimmed = trim($sql);

        if ($trimmed === '') {
            throw BlbQueryException::invalidQuery('Query must not be empty.');
        }

        if (! preg_match('/^\s*SELECT\b/i', $trimmed)) {
            throw BlbQueryException::invalidQuery('Query must start with SELECT.');
        }

        $upperSql = strtoupper($trimmed);
        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (preg_match('/\b'.$keyword.'\b/', $upperSql)) {
                throw BlbQueryException::invalidQuery("Query must not contain {$keyword}.");
            }
        }
    }

    /**
     * Set the current transaction to read-only at the database level.
     *
     * Belt-and-suspenders alongside application-level SELECT validation.
     * SQLite has no transaction-level read-only equivalent, so enforcement
     * is application-level only there.
     *
     * @param  Connection  $connection  The database connection
     */
    private function applyReadOnly(Connection $connection): void
    {
        match ($connection->getDriverName()) {
            'pgsql' => $connection->statement('SET TRANSACTION READ ONLY'),
            default => null,
        };
    }

    /**
     * Apply a statement timeout for the current transaction.
     *
     * @param  Connection  $connection  The database connection
     */
    private function applyTimeout(Connection $connection): void
    {
        $timeoutSeconds = config('database.connections.'.self::CONNECTION.'.query_timeout', self::DEFAULT_TIMEOUT_SECONDS);
        $ms = (int) $timeoutSeconds * 1000;
        $driver = $connection->getDriverName();

        match ($driver) {
            'pgsql' => $connection->statement("SET LOCAL statement_timeout = '{$ms}'"),
            'sqlite' => $connection->statement('PRAGMA busy_timeout = '.$ms),
            'mysql', 'mariadb' => $connection->statement('SET SESSION MAX_EXECUTION_TIME = '.$ms),
            default => null,
        };
    }

    /**
     * Count the total number of rows for the given query.
     *
     * @param  Connection  $connection  The database connection
     * @param  string  $sql  The original SELECT query
     */
    private function countResults(Connection $connection, string $sql): int
    {
        $countSql = "SELECT COUNT(*) AS __blb_count FROM ({$sql}) AS __blb_count_sub";
        $result = $connection->selectOne($countSql);

        return (int) $result->__blb_count;
    }

    /**
     * Extract column names from a query that returned no rows.
     *
     * @param  Connection  $connection  The database connection
     * @param  string  $sql  The original SELECT query
     * @return list<string>
     */
    private function extractColumnsFromEmpty(Connection $connection, string $sql): array
    {
        $emptySql = "SELECT * FROM ({$sql}) AS __blb_view LIMIT 0";
        $statement = $connection->getPdo()->prepare($emptySql);
        $statement->execute();

        $columns = [];
        $columnCount = $statement->columnCount();
        for ($i = 0; $i < $columnCount; $i++) {
            $meta = $statement->getColumnMeta($i);
            if ($meta !== false) {
                $columns[] = $meta['name'];
            }
        }

        return $columns;
    }
}
