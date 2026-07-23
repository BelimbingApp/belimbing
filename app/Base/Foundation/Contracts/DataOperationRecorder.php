<?php

namespace App\Base\Foundation\Contracts;

/**
 * Records a mass data operation onto the shared, Base Database-owned data
 * operation ledger. Any writer — the Data Share mirror, the AX/IBP import,
 * Investment processes — reports through this Foundation contract so it never
 * depends on Data Share internals.
 *
 * Ownership across process boundaries is explicit: the initiator that holds
 * real attribution calls {@see open()} and passes the returned run id to a
 * child that calls {@see resume()}; a child never opens its own run. Direct
 * CLI/scheduler invocation self-opens.
 *
 * The default binding is a no-op so the contract is always safe to depend on;
 * Base Database overrides it with the real ledger recorder.
 */
interface DataOperationRecorder
{
    /**
     * Open a run before mutation begins and return its id.
     *
     * @param  string  $operationType  e.g. mirror_push, mirror_pull, ax_import
     * @param  array<string, mixed>  $attributes  source, direction, transfer_mode,
     *                                            local_instance_id, remote_instance_id, is_forced, schedule_run_ref
     */
    public function open(string $operationType, array $attributes = []): int;

    /**
     * Attach to a run opened by a parent process. Throws if it does not exist.
     */
    public function resume(int $runId): void;

    /**
     * Record one affected table's actions, honest effect counts, and key range.
     *
     * @param  array<string, mixed>  $effect  actions[], rows_inserted, rows_updated,
     *                                        rows_written, rows_deleted, rows_unchanged, rows_rejected, rows_source,
     *                                        rows_attempted, rows_before, rows_after, key_columns[], range_kind,
     *                                        first_key, last_key, local_schema_fingerprint, remote_schema_fingerprint,
     *                                        terminal_status, observed_at
     */
    public function recordTable(int $runId, string $table, array $effect): void;

    /**
     * Finalize a run with a strict terminal status and emit a best-effort,
     * idempotent semantic action referencing the run.
     *
     * @param  string  $status  succeeded|failed|indeterminate
     * @param  array<string, mixed>  $attributes  failure_summary, total_rows_affected
     */
    public function finalize(int $runId, string $status, array $attributes = []): void;
}
