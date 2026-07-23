<?php

namespace App\Base\Database\Services\DataShare\Freshness;

use App\Base\Database\Enums\DataFreshnessState;
use Illuminate\Database\Connection;
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

    public function currentGeneration(string $table): ?int
    {
        if (! Schema::hasTable(self::EVENTS_TABLE)) {
            return null;
        }

        $max = DB::table(self::EVENTS_TABLE)->where('table_name', $table)->max('id');

        return $max === null ? null : (int) $max;
    }

    public function state(
        string $table,
        ?int $acknowledgedGeneration,
        ?Connection $connection = null,
    ): DataFreshnessState {
        if (! $this->driverSupportsTracking($connection)) {
            return DataFreshnessState::Unknown;
        }

        $generation = $this->currentGeneration($table);

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

        $latest = DB::table(self::EVENTS_TABLE)
            ->selectRaw('MAX(id) as max_id')
            ->groupBy('table_name')
            ->pluck('max_id')
            ->all();

        if ($latest === []) {
            return;
        }

        DB::table(self::EVENTS_TABLE)->whereNotIn('id', $latest)->delete();
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
