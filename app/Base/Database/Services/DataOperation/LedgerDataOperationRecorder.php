<?php

namespace App\Base\Database\Services\DataOperation;

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Database\Enums\DataOperationStatus;
use App\Base\Database\Enums\DataOperationType;
use App\Base\Database\Models\DataOperationRun;
use App\Base\Database\Models\DataOperationTableSummary;
use App\Base\Foundation\Contracts\DataOperationRecorder;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Support\TraceId;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Writes the shared data operation ledger and projects one best-effort,
 * idempotent semantic action per terminal run. The ledger is the authoritative
 * history; the audit action only references the run by id.
 *
 * Writes here are deliberately kept out of ordinary mutation auditing — the
 * ledger records mass operations, it is not itself business data.
 */
final class LedgerDataOperationRecorder implements DataOperationRecorder
{
    private const RUNS_TABLE = 'base_database_data_operation_runs';

    public function __construct(
        private readonly SemanticActionRecorder $semanticActions,
    ) {}

    public function open(string $operationType, array $attributes = []): int
    {
        if (! $this->ready()) {
            return 0;
        }

        $actor = $this->currentActor();

        $run = DataOperationRun::query()->create([
            'operation_type' => $operationType,
            'source' => $attributes['source'] ?? null,
            'direction' => $attributes['direction'] ?? null,
            'is_forced' => (bool) ($attributes['is_forced'] ?? false),
            'transfer_mode' => $attributes['transfer_mode'] ?? null,
            'local_instance_id' => $attributes['local_instance_id'] ?? null,
            'remote_instance_id' => $attributes['remote_instance_id'] ?? null,
            'actor_type' => $actor['type'],
            'actor_id' => $actor['id'],
            'company_id' => $actor['company_id'],
            'actor_role' => $actor['role'],
            'actor_label' => $actor['label'],
            'trace_id' => TraceId::current(),
            'schedule_run_ref' => $attributes['schedule_run_ref'] ?? null,
            'status' => DataOperationStatus::Running->value,
            'started_at' => now(),
            'table_count' => 0,
        ]);

        return (int) $run->id;
    }

    public function resume(int $runId): void
    {
        if ($runId <= 0 || ! $this->ready()) {
            return;
        }

        if (! DataOperationRun::query()->whereKey($runId)->exists()) {
            throw new RuntimeException("Cannot resume data operation run #{$runId}: it does not exist.");
        }
    }

    public function recordTable(int $runId, string $table, array $effect): void
    {
        if ($runId <= 0 || ! $this->ready()) {
            return;
        }

        // Upsert on (run_id, table_name) so a resumed/retried operation updates the
        // same summary instead of duplicating it (guarded by the unique index).
        DataOperationTableSummary::query()->updateOrCreate(
            ['run_id' => $runId, 'table_name' => $table],
            [
                'actions' => array_values($effect['actions'] ?? []),
                'rows_source' => $effect['rows_source'] ?? null,
                'rows_attempted' => $effect['rows_attempted'] ?? null,
                'rows_inserted' => $effect['rows_inserted'] ?? null,
                'rows_updated' => $effect['rows_updated'] ?? null,
                'rows_written' => $effect['rows_written'] ?? null,
                'rows_deleted' => $effect['rows_deleted'] ?? null,
                'rows_unchanged' => $effect['rows_unchanged'] ?? null,
                'rows_rejected' => $effect['rows_rejected'] ?? null,
                'rows_before' => $effect['rows_before'] ?? null,
                'rows_after' => $effect['rows_after'] ?? null,
                'key_columns' => $effect['key_columns'] ?? null,
                'range_kind' => $effect['range_kind'] ?? null,
                'first_key' => $effect['first_key'] ?? null,
                'last_key' => $effect['last_key'] ?? null,
                'local_schema_fingerprint' => $effect['local_schema_fingerprint'] ?? null,
                'remote_schema_fingerprint' => $effect['remote_schema_fingerprint'] ?? null,
                'terminal_status' => $effect['terminal_status'] ?? null,
                'observed_at' => $effect['observed_at'] ?? null,
            ],
        );

        // Recompute (not increment) so the count is idempotent under retries.
        DataOperationRun::query()->whereKey($runId)->update([
            'table_count' => DataOperationTableSummary::query()->where('run_id', $runId)->count(),
        ]);
    }

    public function finalize(int $runId, string $status, array $attributes = []): void
    {
        if ($runId <= 0 || ! $this->ready()) {
            return;
        }

        $run = DataOperationRun::query()->find($runId);

        if ($run === null) {
            return;
        }

        $startedAt = $run->started_at instanceof Carbon ? $run->started_at : now();
        $finishedAt = now();

        // Atomically claim terminalization: first terminal status wins, and a
        // concurrent finalize (resumed child + parent fallback, or reconciler)
        // cannot both write. Only a still-running run can be terminalized.
        $claimed = DataOperationRun::query()
            ->whereKey($runId)
            ->where('status', DataOperationStatus::Running->value)
            ->update([
                'status' => $status,
                'finished_at' => $finishedAt,
                'duration_ms' => max(0, (int) $startedAt->diffInMilliseconds($finishedAt)),
                'total_rows_affected' => $attributes['total_rows_affected'] ?? $this->sumAffected($run),
                'failure_summary' => $attributes['failure_summary'] ?? null,
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return; // already terminal — do not overwrite or re-project
        }

        $this->projectSemanticAction($run->refresh());
    }

    /**
     * Best-effort, idempotent audit projection. The audit contract returns void,
     * so we cannot confirm persistence; we atomically claim the single attempt by
     * conditionally setting audit_projection_attempted_at.
     */
    private function projectSemanticAction(DataOperationRun $run): void
    {
        $claimed = DataOperationRun::query()
            ->whereKey($run->id)
            ->whereNull('audit_projection_attempted_at')
            ->update(['audit_projection_attempted_at' => now(), 'updated_at' => now()]);

        if ($claimed === 0) {
            return; // another finalize already emitted (or is emitting) the action
        }

        try {
            $type = $run->operation_type instanceof DataOperationType
                ? $run->operation_type
                : DataOperationType::tryFrom((string) $run->operation_type);
            $status = $run->status instanceof DataOperationStatus
                ? $run->status
                : DataOperationStatus::tryFrom((string) $run->status);

            $this->semanticActions->record(
                event: 'data_operation.'.($type?->value ?? 'unknown'),
                summary: $this->summarize($run, $type, $status),
                source: $run->source,
                subject: [
                    'name' => 'data_operation',
                    'id' => (int) $run->id,
                    'identifier' => $type?->value,
                ],
                context: [
                    'operation_type' => $type?->value,
                    'direction' => $run->direction,
                    'is_forced' => (bool) $run->is_forced,
                    'transfer_mode' => $run->transfer_mode,
                    'local_instance_id' => $run->local_instance_id,
                    'remote_instance_id' => $run->remote_instance_id,
                    'table_count' => (int) $run->table_count,
                    'total_rows_affected' => $run->total_rows_affected,
                    'run_id' => (int) $run->id,
                ],
                result: $status === DataOperationStatus::Succeeded ? 'succeeded' : 'failed',
            );
        } catch (Throwable) {
            // Best-effort by contract: a failed projection never affects the ledger.
        }
    }

    private function summarize(DataOperationRun $run, ?DataOperationType $type, ?DataOperationStatus $status): string
    {
        return sprintf(
            '%s %s: %d table(s)',
            $type?->label() ?? 'Data operation',
            $status?->value ?? 'completed',
            (int) $run->table_count,
        );
    }

    private function sumAffected(DataOperationRun $run): ?int
    {
        $summaries = $run->tables()->get();

        if ($summaries->isEmpty()) {
            return null;
        }

        $total = 0;
        $sawValue = false;

        foreach ($summaries as $summary) {
            $written = $summary->rows_written
                ?? $this->nullableSum($summary->rows_inserted, $summary->rows_updated);
            $deleted = $summary->rows_deleted;

            if ($written !== null) {
                $total += $written;
                $sawValue = true;
            }
            if ($deleted !== null) {
                $total += $deleted;
                $sawValue = true;
            }
        }

        return $sawValue ? $total : null;
    }

    private function nullableSum(?int $a, ?int $b): ?int
    {
        if ($a === null && $b === null) {
            return null;
        }

        return (int) $a + (int) $b;
    }

    /**
     * Resolve the current actor without coupling to the Audit module. Browser
     * runs keep the authenticated user; console/scheduler keep their process type.
     *
     * @return array{type: string, id: int|null, company_id: int|null, role: string|null, label: string|null}
     */
    private function currentActor(): array
    {
        $user = auth()->user();

        if ($user instanceof Authenticatable) {
            return [
                'type' => method_exists($user, 'principalType')
                    ? $user->principalType()->value
                    : PrincipalType::USER->value,
                'id' => (int) $user->getAuthIdentifier(),
                'company_id' => method_exists($user, 'getCompanyId') ? $user->getCompanyId() : null,
                'role' => null,
                'label' => $this->stringOrNull(data_get($user, 'name')) ?? $this->stringOrNull(data_get($user, 'email')),
            ];
        }

        return [
            'type' => app()->runningInConsole() ? PrincipalType::CONSOLE->value : PrincipalType::GUEST->value,
            'id' => null,
            'company_id' => null,
            'role' => null,
            'label' => null,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function ready(): bool
    {
        return Schema::hasTable(self::RUNS_TABLE);
    }
}
