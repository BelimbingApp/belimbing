<?php

namespace App\Base\Database\Services\DataShare\Freshness;

use App\Base\Database\Enums\DataFreshnessState;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Source-change generation tracking, proven on PostgreSQL 17 (see the Phase 3
 * go/no-go decision in docs/plans/data-share-mirror-history-and-freshness.md).
 *
 * A shared statement-level trigger INSERTs one append-only event per write
 * statement inside the source transaction, so every writer is observed —
 * Eloquent, query-builder upserts, raw SQL, COPY, scheduler commands, Investment
 * processes — rolled-back writes leave no event, and TRUNCATE is covered.
 *
 * Append-only is deliberate. An earlier design that UPDATEd one shared row per
 * table deadlocked when concurrent transactions touched multiple tracked tables
 * in opposite order (the row lock is held to commit) and serialized on a hot
 * row. Inserting distinct rows removes both: the deadlock disappears and
 * concurrent throughput improved ~5.7x in benchmarking. Growth is bounded by
 * statement-level firing plus {@see compact()}, which keeps only the latest
 * event per table.
 *
 * The generation of a table is MAX(id) of its events (monotonic). Any
 * non-PostgreSQL driver reports Unknown and installs nothing, so SQLite is
 * truthful rather than falsely Clean. Attaching triggers to production tables is
 * done explicitly via blb:db:share:freshness-attach, never on migrate.
 */
final class DataFreshnessTracker
{
    private const EVENTS_TABLE = 'base_database_data_freshness_events';

    private const FUNCTION_NAME = 'base_database_data_freshness_touch';

    private const TRIGGER_NAME = 'blb_freshness_touch';

    public function driverSupportsTracking(?Connection $connection = null): bool
    {
        return ($connection ?? DB::connection())->getDriverName() === 'pgsql';
    }

    public function installTracking(string $table, ?Connection $connection = null): void
    {
        $connection ??= DB::connection();

        if (! $this->driverSupportsTracking($connection)) {
            return;
        }

        $connection->unprepared($this->functionSql());
        $connection->unprepared($this->triggerSql($table, $connection));
    }

    public function currentGeneration(string $table, ?Connection $connection = null): ?int
    {
        $connection ??= DB::connection();

        if (! $connection->getSchemaBuilder()->hasTable(self::EVENTS_TABLE)) {
            return null;
        }

        $max = $connection->table(self::EVENTS_TABLE)->where('table_name', $table)->max('id');

        return $max === null ? null : (int) $max;
    }

    public function state(
        string $table,
        ?int $acknowledgedGeneration,
        ?Connection $connection = null,
        ?bool $trackingInstalled = null,
    ): DataFreshnessState {
        $connection ??= DB::connection();

        if (! $this->driverSupportsTracking($connection)
            || ! ($trackingInstalled ?? $this->isTrackingInstalled($table, $connection))) {
            return DataFreshnessState::Unknown;
        }

        $generation = $this->currentGeneration($table, $connection);

        if ($generation === null) {
            return DataFreshnessState::Unknown;
        }

        if ($acknowledgedGeneration === null || $generation > $acknowledgedGeneration) {
            return DataFreshnessState::Changed;
        }

        return DataFreshnessState::Clean;
    }

    /**
     * Keep only the latest event per table, so the append-only log stays compact.
     * Safe on any driver: a no-op when the table is absent.
     */
    public function compact(): void
    {
        if (! Schema::hasTable(self::EVENTS_TABLE)) {
            return;
        }

        // One statement makes compaction safe against concurrent trigger inserts:
        // only a row for which a newer same-table row exists is removed.
        DB::delete(<<<'SQL'
            DELETE FROM base_database_data_freshness_events AS older
            WHERE EXISTS (
                SELECT 1
                FROM base_database_data_freshness_events AS newer
                WHERE newer.table_name = older.table_name
                  AND newer.id > older.id
            )
            SQL);
    }

    public function isTrackingInstalled(string $table, ?Connection $connection = null): bool
    {
        $connection ??= DB::connection();

        if (! $this->driverSupportsTracking($connection)) {
            return false;
        }

        return $this->installedTrackingQuery($connection)
            ->where('relation.relname', $table)
            ->exists();
    }

    /** @param list<string> $tables @return array<string, bool> */
    public function trackingStatus(array $tables, ?Connection $connection = null): array
    {
        $connection ??= DB::connection();
        $status = array_fill_keys($tables, false);

        if ($tables === [] || ! $this->driverSupportsTracking($connection)) {
            return $status;
        }

        $installed = $this->installedTrackingQuery($connection)
            ->whereIn('relation.relname', $tables)
            ->pluck('relation.relname');

        foreach ($installed as $table) {
            $status[(string) $table] = true;
        }

        return $status;
    }

    private function installedTrackingQuery(Connection $connection): Builder
    {
        return $connection->table('pg_catalog.pg_trigger as trigger')
            ->join('pg_catalog.pg_class as relation', 'relation.oid', '=', 'trigger.tgrelid')
            ->join('pg_catalog.pg_namespace as namespace', 'namespace.oid', '=', 'relation.relnamespace')
            ->join('pg_catalog.pg_proc as procedure', 'procedure.oid', '=', 'trigger.tgfoid')
            ->join('pg_catalog.pg_namespace as procedure_namespace', 'procedure_namespace.oid', '=', 'procedure.pronamespace')
            ->where('namespace.nspname', 'public')
            ->where('procedure_namespace.nspname', 'public')
            ->where('trigger.tgname', self::TRIGGER_NAME)
            ->where('trigger.tgisinternal', false)
            ->whereIn('trigger.tgenabled', ['O', 'A'])
            ->where('procedure.proname', self::FUNCTION_NAME)
            ->whereRaw('(trigger.tgtype & 1) = 0')
            ->whereRaw('(trigger.tgtype & 2) = 0')
            ->whereRaw('(trigger.tgtype & 60) = 60')
            ->whereRaw('(trigger.tgtype & 64) = 0');
    }

    public function functionSql(): string
    {
        return <<<SQL
        CREATE OR REPLACE FUNCTION {$this->function()}() RETURNS trigger AS \$\$
        BEGIN
            INSERT INTO base_database_data_freshness_events (table_name, occurred_at)
            VALUES (TG_TABLE_NAME, now());
            RETURN NULL;
        END;
        \$\$ LANGUAGE plpgsql;
        SQL;
    }

    public function triggerSql(string $table, ?Connection $connection = null): string
    {
        $wrapped = ($connection ?? DB::connection())->getQueryGrammar()->wrapTable($table);

        return <<<SQL
        DROP TRIGGER IF EXISTS {$this->trigger()} ON {$wrapped};
        CREATE TRIGGER {$this->trigger()}
            AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON {$wrapped}
            FOR EACH STATEMENT
            EXECUTE FUNCTION {$this->function()}();
        SQL;
    }

    private function function(): string
    {
        return self::FUNCTION_NAME;
    }

    private function trigger(): string
    {
        return self::TRIGGER_NAME;
    }
}
