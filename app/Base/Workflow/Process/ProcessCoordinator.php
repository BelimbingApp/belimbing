<?php

namespace App\Base\Workflow\Process;

use App\Base\Workflow\Models\ProcessDefinitionVersion;
use App\Base\Workflow\Models\ProcessDependency as PersistedDependency;
use App\Base\Workflow\Models\ProcessEvent;
use App\Base\Workflow\Models\ProcessRun;
use App\Base\Workflow\Models\ProcessWorkItem;
use App\Base\Workflow\Process\Definitions\ProcessDefinition;
use App\Base\Workflow\Process\Enums\DependencyMode;
use App\Base\Workflow\Process\Enums\ProcessRunStatus;
use App\Base\Workflow\Process\Enums\ProcessWorkStatus;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Durable coordinator for code-defined, multi-step processes.
 *
 * Definitions describe topology once. Persisted runs carry every execution
 * decision needed for retries, timers, fan-out/fan-in, and crash recovery.
 */
class ProcessCoordinator
{
    private const CLAIM_CONTENTION_ATTEMPTS = 8;

    public function __construct(
        private readonly ProcessDefinitionRegistry $definitions,
    ) {}

    /**
     * Start a process once. Reusing an idempotency key returns the original run.
     *
     * @param  array<string, mixed>  $input
     */
    public function start(
        string $definitionKey,
        array $input = [],
        ?string $idempotencyKey = null,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        ?int $definitionVersion = null,
        ?string $correlationKey = null,
        int $priority = 0,
        ?Carbon $availableAt = null,
    ): ProcessRun {
        $definition = $this->definitions->get($definitionKey, $definitionVersion);
        $definitionFingerprint = $definition->fingerprint();
        $this->assertDefinitionVersionIsImmutable($definition, $definitionFingerprint);
        $scopedKey = $idempotencyKey === null
            ? null
            : $this->idempotencyKey('start', $definition->key, $idempotencyKey);

        if ($scopedKey !== null) {
            $existing = ProcessRun::query()->where('idempotency_key', $scopedKey)->first();

            if ($existing !== null) {
                $this->reconcile($existing);

                return $existing->refresh();
            }
        }

        try {
            return DB::transaction(function () use ($definition, $definitionFingerprint, $input, $scopedKey, $subjectType, $subjectId, $correlationKey, $priority, $availableAt): ProcessRun {
                if ($scopedKey !== null) {
                    $existing = ProcessRun::query()
                        ->where('idempotency_key', $scopedKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        return $existing;
                    }
                }

                $now = now();
                $run = ProcessRun::query()->create([
                    'definition_key' => $definition->key,
                    'definition_version' => $definition->version,
                    'definition_fingerprint' => $definitionFingerprint,
                    'status' => ProcessRunStatus::RUNNING,
                    'priority' => $priority,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId === null ? null : (string) $subjectId,
                    'correlation_key' => $correlationKey,
                    'input' => $input,
                    'idempotency_key' => $scopedKey,
                    'started_at' => $now,
                    'available_at' => $availableAt ?? $now,
                    'heartbeat_at' => $now,
                ]);

                $this->appendEvent($run, null, 'process.started', [
                    'definition_key' => $definition->key,
                    'definition_version' => $definition->version,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'correlation_key' => $correlationKey,
                    'priority' => $priority,
                    'available_at' => ($availableAt ?? $now)->toIso8601String(),
                ]);
                $this->materializeDefinitionLocked($run, $definition);
                $this->reconcileLocked($run, $now);

                return $run->refresh();
            });
        } catch (QueryException $exception) {
            if ($scopedKey !== null && ($run = ProcessRun::query()->where('idempotency_key', $scopedKey)->first()) !== null) {
                $this->reconcile($run);

                return $run->refresh();
            }

            throw $exception;
        }
    }

    /**
     * Deliver an external fact to waiting steps. A named signal is one-shot per
     * run by default; pass a distinct idempotency key only for repeatable facts.
     *
     * @param  array<string, mixed>  $payload
     */
    public function signal(ProcessRun|int $run, string $signal, array $payload = [], ?string $idempotencyKey = null): ProcessRun
    {
        if (trim($signal) === '') {
            throw new ProcessCoordinationException('A process signal name cannot be empty.');
        }

        $runId = $this->runId($run);
        $eventKey = $this->idempotencyKey('signal', (string) $runId, $idempotencyKey ?? $signal);

        return DB::transaction(function () use ($runId, $signal, $payload, $eventKey): ProcessRun {
            $lockedRun = $this->lockRun($runId);

            if (ProcessEvent::query()->where('idempotency_key', $eventKey)->exists()) {
                return $lockedRun;
            }

            if ($lockedRun->status->terminal()) {
                $this->appendEvent($lockedRun, null, 'signal.ignored', [
                    'signal' => $signal,
                    'reason' => 'process_terminal',
                    'payload' => $payload,
                ], $eventKey);

                return $lockedRun->refresh();
            }

            $this->materializeDefinitionLocked(
                $lockedRun,
                $this->definitions->get($lockedRun->definition_key, $lockedRun->definition_version),
            );

            $now = now();
            $matchedItems = ProcessWorkItem::query()
                ->where('process_run_id', $lockedRun->id)
                ->where('required_signal', $signal)
                ->whereNull('signalled_at')
                ->whereIn('status', [ProcessWorkStatus::PENDING->value, ProcessWorkStatus::AVAILABLE->value])
                ->lockForUpdate()
                ->get();

            foreach ($matchedItems as $workItem) {
                $workItem->forceFill([
                    'signalled_at' => $now,
                    'signal_payload' => $payload,
                ])->save();
            }

            $this->appendEvent($lockedRun, null, 'signal.received', [
                'signal' => $signal,
                'matched_work_items' => $matchedItems->count(),
                'payload' => $payload,
            ], $eventKey);
            $this->reconcileLocked($lockedRun, $now);

            return $lockedRun->refresh();
        });
    }

    /**
     * Repair expired leases and advance every satisfiable dependency/timer.
     *
     * @return int Number of runs reconciled
     */
    public function reconcile(ProcessRun|int|null $run = null): int
    {
        $ids = $run === null
            ? ProcessRun::query()->where('status', ProcessRunStatus::RUNNING->value)->orderBy('id')->pluck('id')->all()
            : [$this->runId($run)];
        $reconciled = 0;

        foreach ($ids as $id) {
            $handled = DB::transaction(function () use ($id): bool {
                $lockedRun = ProcessRun::query()->whereKey($id)->lockForUpdate()->first();

                if ($lockedRun === null || $lockedRun->status->terminal()) {
                    return false;
                }

                if (! $this->materializeRegisteredDefinitionLocked($lockedRun)) {
                    return true;
                }

                if ($lockedRun->status === ProcessRunStatus::RUNNING) {
                    $this->reconcileLocked($lockedRun, now());
                }

                return true;
            });

            $reconciled += $handled ? 1 : 0;
        }

        return $reconciled;
    }

    /**
     * Claim one due unit of work. The returned lease token is required for all
     * worker-owned mutations, so a late worker cannot overwrite a reassignment.
     *
     * @param  list<string>|null  $executorKeys
     * @param  list<int>|null  $processRunIds
     */
    public function claim(
        string $worker,
        int $leaseSeconds = 300,
        ?string $definitionKey = null,
        ?array $executorKeys = null,
        ?array $processRunIds = null,
    ): ?WorkClaim {
        if (trim($worker) === '' || $leaseSeconds < 1) {
            throw new ProcessCoordinationException('A claim needs a worker name and positive lease duration.');
        }

        if ($executorKeys !== null && $executorKeys === []) {
            return null;
        }

        foreach ($executorKeys ?? [] as $executorKey) {
            if (! is_string($executorKey) || trim($executorKey) === '') {
                throw new ProcessCoordinationException('Claim executor keys must be non-empty strings.');
            }
        }

        if ($processRunIds !== null && $processRunIds === []) {
            return null;
        }

        foreach ($processRunIds ?? [] as $processRunId) {
            if (! is_int($processRunId) || $processRunId < 1) {
                throw new ProcessCoordinationException('Claim process run ids must be positive integers.');
            }
        }

        $processRunIds = $processRunIds === null
            ? null
            : array_values(array_unique($processRunIds));

        if ($processRunIds === null) {
            $this->reconcile();
        } else {
            foreach ($processRunIds as $processRunId) {
                $this->reconcile($processRunId);
            }
        }

        for ($attempt = 0; $attempt < self::CLAIM_CONTENTION_ATTEMPTS; $attempt++) {
            $candidate = $this->findClaimCandidate($definitionKey, $executorKeys, $processRunIds);

            if ($candidate === null) {
                return null;
            }

            $claim = $this->claimCandidate(
                $candidate,
                $worker,
                $leaseSeconds,
                $definitionKey,
                $executorKeys,
                $processRunIds,
            );

            if ($claim !== null) {
                return $claim;
            }
        }

        return null;
    }

    /**
     * Select optimistically so the database never chooses a lock order across
     * the joined process and work-item tables. Eligibility is proved again
     * after both records have been locked in the canonical run-then-item order.
     *
     * @param  list<string>|null  $executorKeys
     * @param  list<int>|null  $processRunIds
     */
    private function findClaimCandidate(
        ?string $definitionKey,
        ?array $executorKeys,
        ?array $processRunIds,
    ): ?ProcessWorkItem {
        $now = now();
        $query = ProcessWorkItem::query()
            ->select('base_workflow_process_work_items.*')
            ->join(
                'base_workflow_process_runs',
                'base_workflow_process_runs.id',
                '=',
                'base_workflow_process_work_items.process_run_id'
            )
            ->where('base_workflow_process_runs.status', ProcessRunStatus::RUNNING->value)
            ->whereNull('base_workflow_process_runs.last_error')
            ->where('base_workflow_process_runs.available_at', '<=', $now)
            ->where('base_workflow_process_work_items.status', ProcessWorkStatus::AVAILABLE->value)
            ->where('base_workflow_process_work_items.available_at', '<=', $now);

        if ($definitionKey !== null) {
            $query->where('base_workflow_process_runs.definition_key', $definitionKey);
        }

        if ($executorKeys !== null) {
            $query->whereIn('base_workflow_process_work_items.executor_key', $executorKeys);
        }

        if ($processRunIds !== null) {
            $query->whereIn('base_workflow_process_runs.id', $processRunIds);
        }

        return $query
            ->orderByDesc('base_workflow_process_runs.priority')
            ->orderByDesc('base_workflow_process_work_items.priority')
            ->orderBy('base_workflow_process_work_items.available_at')
            ->orderBy('base_workflow_process_work_items.id')
            ->first();
    }

    /**
     * @param  list<string>|null  $executorKeys
     * @param  list<int>|null  $processRunIds
     */
    private function claimCandidate(
        ProcessWorkItem $candidate,
        string $worker,
        int $leaseSeconds,
        ?string $definitionKey,
        ?array $executorKeys,
        ?array $processRunIds,
    ): ?WorkClaim {
        return DB::transaction(function () use ($candidate, $worker, $leaseSeconds, $definitionKey, $executorKeys, $processRunIds): ?WorkClaim {
            $run = ProcessRun::query()
                ->whereKey($candidate->process_run_id)
                ->lockForUpdate()
                ->first();

            if ($run === null) {
                return null;
            }

            $workItem = ProcessWorkItem::query()
                ->whereKey($candidate->getKey())
                ->lockForUpdate()
                ->first();
            $now = now();

            if ($workItem === null || ! $this->claimCandidateIsEligible(
                $run,
                $workItem,
                $now,
                $definitionKey,
                $executorKeys,
                $processRunIds,
            )) {
                return null;
            }

            $token = (string) Str::uuid();

            $workItem->forceFill([
                'status' => ProcessWorkStatus::LEASED,
                'attempts' => $workItem->attempts + 1,
                'lease_owner' => $worker,
                'lease_token' => $token,
                'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                'heartbeat_at' => $now,
            ])->save();

            $run->forceFill(['heartbeat_at' => $now])->save();
            $this->appendEvent($run, $workItem, 'work.claimed', [
                'worker' => $worker,
                'attempt' => $workItem->attempts,
                'lease_expires_at' => $workItem->lease_expires_at?->toIso8601String(),
            ]);

            return new WorkClaim($workItem->refresh(), $token);
        });
    }

    /**
     * @param  list<string>|null  $executorKeys
     * @param  list<int>|null  $processRunIds
     */
    private function claimCandidateIsEligible(
        ProcessRun $run,
        ProcessWorkItem $workItem,
        Carbon $now,
        ?string $definitionKey,
        ?array $executorKeys,
        ?array $processRunIds,
    ): bool {
        return (int) $workItem->process_run_id === (int) $run->getKey()
            && $run->status === ProcessRunStatus::RUNNING
            && $run->last_error === null
            && $run->available_at !== null
            && $run->available_at->lte($now)
            && $workItem->status === ProcessWorkStatus::AVAILABLE
            && $workItem->available_at !== null
            && $workItem->available_at->lte($now)
            && ($definitionKey === null || $run->definition_key === $definitionKey)
            && ($executorKeys === null || in_array($workItem->executor_key, $executorKeys, true))
            && ($processRunIds === null || in_array((int) $run->getKey(), $processRunIds, true));
    }

    public function heartbeat(WorkClaim $claim, int $leaseSeconds = 300): ProcessWorkItem
    {
        if ($leaseSeconds < 1) {
            throw new ProcessCoordinationException('A heartbeat needs a positive lease duration.');
        }

        return DB::transaction(function () use ($claim, $leaseSeconds): ProcessWorkItem {
            [$run, $workItem] = $this->lockClaim($claim);
            $now = now();

            $workItem->forceFill([
                'heartbeat_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
            ])->save();
            $run->forceFill(['heartbeat_at' => $now])->save();
            $this->appendEvent($run, $workItem, 'work.heartbeat', [
                'lease_expires_at' => $workItem->lease_expires_at?->toIso8601String(),
            ]);

            return $workItem->refresh();
        });
    }

    /**
     * Fence an external module mutation to the caller's still-active lease.
     *
     * This assertion deliberately requires an existing outer transaction. The
     * process and work-item locks are then retained until the caller's domain
     * write commits, preventing recovery or reassignment from racing the result.
     */
    public function assertClaimActive(WorkClaim $claim): ProcessWorkItem
    {
        if (DB::transactionLevel() < 1) {
            throw new ProcessCoordinationException(
                'Claim fencing must run inside the same database transaction as the protected mutation.',
            );
        }

        [, $workItem] = $this->lockClaim($claim);

        return $workItem;
    }

    /** @param array<string, mixed> $output */
    public function complete(
        WorkClaim $claim,
        array $output = [],
        string $outcome = 'completed',
        ?string $resultRef = null,
    ): ProcessWorkItem {
        if (trim($outcome) === '') {
            throw new ProcessCoordinationException('A completed work item needs an outcome.');
        }

        return DB::transaction(function () use ($claim, $output, $outcome, $resultRef): ProcessWorkItem {
            [$run, $workItem] = $this->lockClaim($claim, allowTerminal: true);

            if ($workItem->status->terminal()) {
                return $workItem;
            }

            $now = now();
            $this->finishWork($workItem, ProcessWorkStatus::COMPLETED, $outcome, $output, null, $now);
            $workItem->forceFill(['result_ref' => $resultRef ?? $workItem->result_ref])->save();
            $this->appendEvent($run, $workItem, 'work.completed', [
                'outcome' => $outcome,
                'output' => $output,
                'result_ref' => $workItem->result_ref,
            ]);
            $this->reconcileLocked($run, $now);

            return $workItem->refresh();
        });
    }

    public function fail(
        WorkClaim $claim,
        string $error,
        ?Carbon $retryAt = null,
        bool $retryable = true,
        string $failureCategory = 'unclassified',
    ): ProcessWorkItem {
        if (trim($error) === '' || trim($failureCategory) === '') {
            throw new ProcessCoordinationException('A failed work item needs an error and failure category.');
        }

        return DB::transaction(function () use ($claim, $error, $retryAt, $retryable, $failureCategory): ProcessWorkItem {
            [$run, $workItem] = $this->lockClaim($claim, allowTerminal: true);

            if ($workItem->status->terminal()) {
                return $workItem;
            }

            $now = now();

            $failure = [
                'retryable' => $retryable,
                'category' => $failureCategory,
            ];

            if ($retryable && $workItem->attempts < $workItem->max_attempts) {
                $workItem->forceFill([
                    'status' => ProcessWorkStatus::PENDING,
                    'available_at' => $retryAt ?? $now,
                    'lease_owner' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => null,
                    'last_error' => $error,
                ])->save();
                $this->appendEvent($run, $workItem, 'work.retry_scheduled', [
                    'error' => $error,
                    'failure' => $failure,
                    'available_at' => $workItem->available_at?->toIso8601String(),
                ]);
            } else {
                $this->finishWork(
                    $workItem,
                    ProcessWorkStatus::FAILED,
                    'failed',
                    ['failure' => $failure],
                    $error,
                    $now,
                );
                $this->appendEvent($run, $workItem, 'work.failed', [
                    'error' => $error,
                    'failure' => $failure,
                ]);
            }

            $this->reconcileLocked($run, $now);

            return $workItem->refresh();
        });
    }

    public function waive(ProcessWorkItem|int $workItem, string $reason, string $outcome = 'waived'): ProcessWorkItem
    {
        if (trim($reason) === '' || trim($outcome) === '') {
            throw new ProcessCoordinationException('Waiving process work requires a reason and outcome.');
        }

        return $this->administrativelyFinish($workItem, ProcessWorkStatus::WAIVED, $outcome, $reason);
    }

    public function block(ProcessWorkItem|int $workItem, string $reason): ProcessWorkItem
    {
        if (trim($reason) === '') {
            throw new ProcessCoordinationException('Blocking process work requires a reason.');
        }

        return $this->administrativelyFinish($workItem, ProcessWorkStatus::BLOCKED, 'blocked', $reason);
    }

    /**
     * Terminate the currently leased claim as a durable blocker while keeping
     * the worker's typed diagnostic payload. Administrative block() remains
     * the owner/operator path and intentionally has no claim output.
     *
     * @param  array<string, mixed>  $output
     */
    public function blockClaim(
        WorkClaim $claim,
        string $reason,
        array $output = [],
        ?string $resultRef = null,
    ): ProcessWorkItem {
        if (trim($reason) === '') {
            throw new ProcessCoordinationException('Blocking claimed process work requires a reason.');
        }

        return DB::transaction(function () use ($claim, $reason, $output, $resultRef): ProcessWorkItem {
            [$run, $workItem] = $this->lockClaim($claim, allowTerminal: true);

            if ($workItem->status->terminal()) {
                return $workItem;
            }

            $now = now();
            $this->finishWork($workItem, ProcessWorkStatus::BLOCKED, 'blocked', $output, $reason, $now);
            $workItem->forceFill(['result_ref' => $resultRef ?? $workItem->result_ref])->save();
            $this->appendEvent($run, $workItem, 'work.blocked', [
                'outcome' => 'blocked',
                'reason' => $reason,
                'output' => $output,
                'result_ref' => $workItem->result_ref,
            ]);
            $this->reconcileLocked($run, $now);

            return $workItem->refresh();
        });
    }

    public function pause(ProcessRun|int $run, string $reason): ProcessRun
    {
        if (trim($reason) === '') {
            throw new ProcessCoordinationException('Pausing a process requires a reason.');
        }

        return DB::transaction(function () use ($run, $reason): ProcessRun {
            $lockedRun = $this->lockRun($this->runId($run));

            if ($lockedRun->status === ProcessRunStatus::PAUSED) {
                return $lockedRun;
            }

            if ($lockedRun->status !== ProcessRunStatus::RUNNING) {
                throw new ProcessCoordinationException('Only a running process can be paused.');
            }

            $now = now();
            $lockedRun->forceFill([
                'status' => ProcessRunStatus::PAUSED,
                'paused_at' => $now,
                'pause_reason' => $reason,
                'heartbeat_at' => $now,
            ])->save();
            $this->appendEvent($lockedRun, null, 'process.paused', ['reason' => $reason]);

            return $lockedRun->refresh();
        });
    }

    public function resume(ProcessRun|int $run): ProcessRun
    {
        return DB::transaction(function () use ($run): ProcessRun {
            $lockedRun = $this->lockRun($this->runId($run));

            if ($lockedRun->status === ProcessRunStatus::RUNNING) {
                return $lockedRun;
            }

            if ($lockedRun->status !== ProcessRunStatus::PAUSED) {
                throw new ProcessCoordinationException('Only a paused process can be resumed.');
            }

            $now = now();
            $lockedRun->forceFill([
                'status' => ProcessRunStatus::RUNNING,
                'paused_at' => null,
                'pause_reason' => null,
                'heartbeat_at' => $now,
            ])->save();
            $this->appendEvent($lockedRun, null, 'process.resumed');
            $this->reconcileLocked($lockedRun, $now);

            return $lockedRun->refresh();
        });
    }

    /**
     * Atomically retire an obsolete process graph without revoking live work.
     *
     * A null result means the graph was not superseded because it was already
     * terminal or a worker still owns an unexpired lease. Expired leases may
     * be retired because their former worker has already lost the right to
     * commit through assertClaimActive().
     */
    public function supersede(ProcessRun|int $run, string $reason): ?ProcessRun
    {
        if (trim($reason) === '') {
            throw new ProcessCoordinationException('Superseding a process requires a reason.');
        }

        return DB::transaction(function () use ($run, $reason): ?ProcessRun {
            $lockedRun = $this->lockRun($this->runId($run));

            if ($lockedRun->status->terminal()) {
                return null;
            }

            $items = ProcessWorkItem::query()
                ->where('process_run_id', $lockedRun->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $now = now();
            $hasLiveLease = $items->contains(
                fn (ProcessWorkItem $item): bool => $item->status === ProcessWorkStatus::LEASED
                    && $item->lease_expires_at !== null
                    && $item->lease_expires_at->gt($now),
            );

            if ($hasLiveLease) {
                return null;
            }

            foreach ($items as $item) {
                if ($item->status->terminal()) {
                    continue;
                }

                $this->finishWork($item, ProcessWorkStatus::BLOCKED, 'blocked', null, $reason, $now);
                $this->appendEvent($lockedRun, $item, 'work.blocked', [
                    'outcome' => 'blocked',
                    'reason' => $reason,
                    'cause' => 'process_superseded',
                ]);
            }

            $this->finishRunIfTerminal($lockedRun, $now);
            $this->appendEvent($lockedRun, null, 'process.superseded', ['reason' => $reason]);

            return $lockedRun->refresh();
        });
    }

    /** @return array{ProcessRun, ProcessWorkItem} */
    private function lockClaim(WorkClaim $claim, bool $allowTerminal = false): array
    {
        $runId = (int) $claim->workItem->process_run_id;
        $run = $this->lockRun($runId);
        $workItem = ProcessWorkItem::query()->whereKey($claim->workItem->getKey())->lockForUpdate()->firstOrFail();

        if ($allowTerminal && $workItem->status->terminal()) {
            return [$run, $workItem];
        }

        if ($workItem->status !== ProcessWorkStatus::LEASED || ! hash_equals((string) $workItem->lease_token, $claim->leaseToken)) {
            throw new ProcessCoordinationException('The process work lease is no longer owned by this worker.');
        }

        if ($workItem->lease_expires_at === null || $workItem->lease_expires_at->lte(now())) {
            throw new ProcessCoordinationException('The process work lease has expired.');
        }

        return [$run, $workItem];
    }

    private function administrativelyFinish(
        ProcessWorkItem|int $workItem,
        ProcessWorkStatus $status,
        string $outcome,
        string $reason,
    ): ProcessWorkItem {
        $workItemId = $workItem instanceof ProcessWorkItem ? (int) $workItem->getKey() : $workItem;
        $runId = (int) ProcessWorkItem::query()->whereKey($workItemId)->value('process_run_id');

        return DB::transaction(function () use ($workItemId, $runId, $status, $outcome, $reason): ProcessWorkItem {
            $run = $this->lockRun($runId);
            $lockedItem = ProcessWorkItem::query()->whereKey($workItemId)->lockForUpdate()->firstOrFail();

            if ($lockedItem->status->terminal()) {
                return $lockedItem;
            }

            $now = now();
            $this->finishWork($lockedItem, $status, $outcome, null, $reason, $now);
            $this->appendEvent($run, $lockedItem, 'work.'.$status->value, [
                'outcome' => $outcome,
                'reason' => $reason,
            ]);
            $this->reconcileLocked($run, $now);

            return $lockedItem->refresh();
        });
    }

    private function materializeDefinitionLocked(ProcessRun $run, ProcessDefinition $definition): void
    {
        $items = [];

        foreach ($definition->steps as $step) {
            $workItem = ProcessWorkItem::query()->firstOrCreate(
                [
                    'process_run_id' => $run->id,
                    'step_key' => $step->key,
                ],
                [
                    'label' => $step->label,
                    'executor_key' => $step->resolvedExecutorKey(),
                    'status' => ProcessWorkStatus::PENDING,
                    'dependency_mode' => $step->dependencyMode,
                    'required_signal' => $step->requiredSignal,
                    'delay_seconds' => $step->delaySeconds,
                    'max_attempts' => $step->maxAttempts,
                    'priority' => $step->priority,
                    'input' => $step->input,
                    'input_ref' => $step->inputRef,
                    'result_ref' => $step->resultRef,
                    'metadata' => $step->metadata,
                ],
            );

            if ($workItem->wasRecentlyCreated) {
                $this->appendEvent($run, $workItem, 'work.materialized', [
                    'step_key' => $step->key,
                    'executor_key' => $step->resolvedExecutorKey(),
                ]);
            }

            $items[$step->key] = $workItem;
        }

        foreach ($definition->steps as $step) {
            foreach ($step->dependencies as $dependency) {
                $acceptable = array_values(array_unique($dependency->acceptableOutcomes));
                $edge = PersistedDependency::query()->firstOrCreate(
                    [
                        'work_item_id' => $items[$step->key]->id,
                        'depends_on_work_item_id' => $items[$dependency->stepKey]->id,
                    ],
                    ['acceptable_outcomes' => $acceptable],
                );

                $persisted = $edge->acceptable_outcomes;
                sort($persisted);
                sort($acceptable);

                if ($persisted !== $acceptable) {
                    throw new ProcessCoordinationException(
                        "Process definition [{$definition->key}:{$definition->version}] changed acceptable outcomes without a version bump."
                    );
                }
            }
        }
    }

    private function materializeRegisteredDefinitionLocked(ProcessRun $run): bool
    {
        $prefix = 'Process definition cannot be reconciled: ';

        try {
            $definition = $this->definitions->get($run->definition_key, $run->definition_version);
            $this->assertRunDefinitionMatches($run, $definition);
            $this->materializeDefinitionLocked($run, $definition);

            if (str_starts_with((string) $run->last_error, $prefix)) {
                $run->forceFill(['last_error' => null])->save();
                $this->appendEvent($run, null, 'process.definition_restored', [
                    'definition_key' => $run->definition_key,
                    'definition_version' => $run->definition_version,
                ]);
            }

            return true;
        } catch (DomainException $exception) {
            $error = $prefix.$exception->getMessage();

            if ($run->last_error !== $error) {
                $run->forceFill(['last_error' => $error, 'heartbeat_at' => now()])->save();
                $this->appendEvent($run, null, 'process.definition_unavailable', [
                    'definition_key' => $run->definition_key,
                    'definition_version' => $run->definition_version,
                ]);
            }

            return false;
        }
    }

    private function reconcileLocked(ProcessRun $run, Carbon $now): void
    {
        $expired = ProcessWorkItem::query()
            ->where('process_run_id', $run->id)
            ->where('status', ProcessWorkStatus::LEASED->value)
            ->where('lease_expires_at', '<=', $now)
            ->lockForUpdate()
            ->get();

        foreach ($expired as $workItem) {
            if ($workItem->attempts >= $workItem->max_attempts) {
                $this->finishWork(
                    $workItem,
                    ProcessWorkStatus::FAILED,
                    'failed',
                    null,
                    'Worker lease expired after the final attempt.',
                    $now,
                );
                $event = 'work.lease_expired_failed';
            } else {
                $workItem->forceFill([
                    'status' => ProcessWorkStatus::PENDING,
                    'available_at' => $now,
                    'lease_owner' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => null,
                    'last_error' => 'Worker lease expired; work returned to the queue.',
                ])->save();
                $event = 'work.lease_expired_requeued';
            }

            $this->appendEvent($run, $workItem, $event, ['attempt' => $workItem->attempts]);
        }

        do {
            $changed = false;
            $pending = ProcessWorkItem::query()
                ->where('process_run_id', $run->id)
                ->where('status', ProcessWorkStatus::PENDING->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($pending as $workItem) {
                $dependencyState = $this->dependencyState($workItem);

                if ($dependencyState === 'impossible') {
                    $this->finishWork(
                        $workItem,
                        ProcessWorkStatus::BLOCKED,
                        'blocked',
                        null,
                        'No acceptable dependency outcome remains.',
                        $now,
                    );
                    $this->appendEvent($run, $workItem, 'work.blocked', [
                        'reason' => 'No acceptable dependency outcome remains.',
                    ]);
                    $changed = true;

                    continue;
                }

                if ($dependencyState !== 'satisfied' || ($workItem->required_signal !== null && $workItem->signalled_at === null)) {
                    continue;
                }

                if ($workItem->available_at === null) {
                    $availabilityBase = $run->available_at->gt($now)
                        ? $run->available_at->copy()
                        : $now->copy();
                    $workItem->forceFill([
                        'available_at' => $availabilityBase->addSeconds($workItem->delay_seconds),
                    ])->save();
                    $this->appendEvent($run, $workItem, 'work.timer_started', [
                        'available_at' => $workItem->available_at?->toIso8601String(),
                    ]);
                }

                if ($workItem->available_at->lte($now)) {
                    $workItem->forceFill(['status' => ProcessWorkStatus::AVAILABLE])->save();
                    $this->appendEvent($run, $workItem, 'work.available');
                    $changed = true;
                }
            }
        } while ($changed);

        $run->forceFill(['heartbeat_at' => $now])->save();
        $this->finishRunIfTerminal($run, $now);
    }

    private function dependencyState(ProcessWorkItem $workItem): string
    {
        $dependencies = PersistedDependency::query()
            ->where('work_item_id', $workItem->id)
            ->with('prerequisite')
            ->get();

        if ($dependencies->isEmpty()) {
            return 'satisfied';
        }

        $accepted = 0;
        $terminal = 0;

        foreach ($dependencies as $dependency) {
            $prerequisite = $dependency->prerequisite;

            if ($prerequisite === null) {
                $terminal++;

                continue;
            }

            if ($prerequisite->status->terminal()) {
                $terminal++;

                if (in_array($prerequisite->outcome, $dependency->acceptable_outcomes, true)) {
                    $accepted++;
                }
            }
        }

        if ($workItem->dependency_mode === DependencyMode::ANY) {
            if ($accepted > 0) {
                return 'satisfied';
            }

            return $terminal === $dependencies->count() ? 'impossible' : 'waiting';
        }

        if ($terminal > $accepted) {
            return 'impossible';
        }

        return $accepted === $dependencies->count() ? 'satisfied' : 'waiting';
    }

    private function finishRunIfTerminal(ProcessRun $run, Carbon $now): void
    {
        if ($run->status->terminal()) {
            return;
        }

        $items = ProcessWorkItem::query()->where('process_run_id', $run->id)->get();

        if ($items->contains(fn (ProcessWorkItem $item): bool => ! $item->status->terminal())) {
            return;
        }

        $status = match (true) {
            $items->contains(fn (ProcessWorkItem $item): bool => $item->status === ProcessWorkStatus::FAILED) => ProcessRunStatus::FAILED,
            $items->contains(fn (ProcessWorkItem $item): bool => $item->status === ProcessWorkStatus::BLOCKED) => ProcessRunStatus::BLOCKED,
            default => ProcessRunStatus::COMPLETED,
        };

        $output = $items->mapWithKeys(fn (ProcessWorkItem $item): array => [
            $item->step_key => [
                'status' => $item->status->value,
                'outcome' => $item->outcome,
                'output' => $item->output,
            ],
        ])->all();

        $run->forceFill([
            'status' => $status,
            'output' => $output,
            'last_error' => $items->first(fn (ProcessWorkItem $item): bool => $item->last_error !== null)?->last_error,
            'completed_at' => $now,
            'heartbeat_at' => $now,
        ])->save();
        $this->appendEvent($run, null, 'process.'.$status->value, ['output' => $output]);
    }

    /** @param array<string, mixed>|null $output */
    private function finishWork(
        ProcessWorkItem $workItem,
        ProcessWorkStatus $status,
        string $outcome,
        ?array $output,
        ?string $error,
        Carbon $now,
    ): void {
        $workItem->forceFill([
            'status' => $status,
            'outcome' => $outcome,
            'output' => $output,
            'last_error' => $error,
            'lease_owner' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => null,
            'completed_at' => $now,
        ])->save();
    }

    /** @param array<string, mixed> $payload */
    private function appendEvent(
        ProcessRun $run,
        ?ProcessWorkItem $workItem,
        string $type,
        array $payload = [],
        ?string $idempotencyKey = null,
    ): ProcessEvent {
        $sequence = (int) ProcessEvent::query()
            ->where('process_run_id', $run->id)
            ->max('sequence') + 1;

        return ProcessEvent::query()->create([
            'process_run_id' => $run->id,
            'work_item_id' => $workItem?->id,
            'sequence' => $sequence,
            'type' => $type,
            'payload' => $payload === [] ? null : $payload,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => now(),
        ]);
    }

    private function lockRun(int $runId): ProcessRun
    {
        return ProcessRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
    }

    private function runId(ProcessRun|int $run): int
    {
        return $run instanceof ProcessRun ? (int) $run->getKey() : $run;
    }

    private function idempotencyKey(string ...$parts): string
    {
        $raw = implode(':', $parts);

        return strlen($raw) <= 240 ? $raw : substr($raw, 0, 170).':'.hash('sha256', $raw);
    }

    private function assertDefinitionVersionIsImmutable(ProcessDefinition $definition, string $fingerprint): void
    {
        $registered = ProcessDefinitionVersion::query()->firstOrCreate(
            [
                'definition_key' => $definition->key,
                'definition_version' => $definition->version,
            ],
            ['definition_fingerprint' => $fingerprint],
        );

        if (! hash_equals($registered->definition_fingerprint, $fingerprint)) {
            throw new ProcessCoordinationException(
                "Process definition [{$definition->key}:{$definition->version}] changed after use; publish a new version."
            );
        }
    }

    private function assertRunDefinitionMatches(ProcessRun $run, ProcessDefinition $definition): void
    {
        if (! hash_equals($run->definition_fingerprint, $definition->fingerprint())) {
            throw new ProcessCoordinationException(
                "Process definition [{$definition->key}:{$definition->version}] changed after this run started; publish a new version."
            );
        }
    }
}
