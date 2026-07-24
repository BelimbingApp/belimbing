<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorCatalogTable;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorConnectionStatus;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorExecutionResult;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorProgress;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReview;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReviewItem;
use App\Base\Database\Enums\DataOperationRangeKind;
use App\Base\Database\Enums\DataOperationStatus;
use App\Base\Database\Enums\DataOperationType;
use App\Base\Database\Enums\DataShareMirrorAction;
use App\Base\Database\Enums\DataShareMirrorDirection;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Services\DataShare\DataShareInstanceIdentityResolver;
use App\Base\Database\Services\DataShare\Freshness\DataFreshnessTracker;
use App\Base\Foundation\Contracts\DataOperationRecorder;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

class DataShareMirrorManager
{
    public function __construct(
        private readonly DataShareMirrorConnectionManager $connections,
        private readonly DataShareMirrorCatalog $catalog,
        private readonly DataShareMirrorReviewer $reviewer,
        private readonly DataShareMirrorEngineRegistry $engines,
        private readonly DataShareMirrorOperationLock $operationLock,
        private readonly DataOperationRecorder $operations,
    ) {}

    public function status(): DataShareMirrorConnectionStatus
    {
        return $this->connections->status();
    }

    public function testConnection(string $candidateUrl, ?string $provider = null): DataShareMirrorConnectionStatus
    {
        return $this->connections->testConnection($candidateUrl, $provider);
    }

    /** @return array<string, string> */
    public function providerOptions(): array
    {
        return $this->connections->providerOptions();
    }

    public function configurationFingerprint(): string
    {
        return $this->connections->configurationFingerprint();
    }

    public function disconnect(): void
    {
        $this->connections->purge();
    }

    /** @return list<DataShareMirrorCatalogTable> */
    public function catalog(): array
    {
        try {
            return $this->mergeCatalogObservations($this->catalog->catalog());
        } catch (DataShareMirrorException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw DataShareMirrorException::unexpected('catalog', $exception);
        }
    }

    /**
     * Local-first catalog: Local registry rows only, with no remote call, so the
     * UI can render immediately. Remote presence, counts, and freshness arrive via
     * a separate {@see catalog()} enrichment request after the first render.
     *
     * @return list<DataShareMirrorCatalogTable>
     */
    public function localCatalog(): array
    {
        try {
            return $this->catalog->localCatalog();
        } catch (DataShareMirrorException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw DataShareMirrorException::unexpected('catalog', $exception);
        }
    }

    /**
     * Merge persisted observations into the catalog. Best-effort: any failure
     * to resolve endpoint identity leaves the base catalog untouched.
     *
     * @param  list<DataShareMirrorCatalogTable>  $tables
     * @return list<DataShareMirrorCatalogTable>
     */
    private function mergeCatalogObservations(array $tables): array
    {
        try {
            $localInstanceId = $this->localInstanceId();
            $remoteInstanceId = $this->remoteInstanceId();

            if ($localInstanceId === null || $remoteInstanceId === null) {
                return $tables;
            }

            return $this->catalog->mergeObservations($tables, $localInstanceId, $remoteInstanceId);
        } catch (Throwable) {
            return $tables;
        }
    }

    /** @param list<string> $tableNames */
    public function review(string $direction, array $tableNames): DataShareMirrorReview
    {
        try {
            return $this->reviewer->review(DataShareMirrorDirection::parse($direction), $tableNames);
        } catch (DataShareMirrorException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw DataShareMirrorException::unexpected('review', $exception);
        }
    }

    /**
     * Recompute policy immediately before mutation. Web callers pass the
     * transient token from their visible review; CLI callers may omit it only
     * when review and execute happen in the same command invocation.
     *
     * @param  list<string>  $tableNames
     */
    public function execute(string $direction, array $tableNames, ?string $expectedStateToken = null, ?callable $progress = null): DataShareMirrorExecutionResult
    {
        $reporter = DataShareMirrorProgress::listen($progress);
        $reporter->report((string) __('Waiting for the mirror operation lock.'));

        try {
            return $this->operationLock->run(function () use ($direction, $tableNames, $expectedStateToken, $reporter): DataShareMirrorExecutionResult {
                $reporter->report((string) __('Operation lock acquired. Revalidating the reviewed selection.'));
                $review = $this->review($direction, $tableNames);

                // Reviews that stay blocked or stale never create mutation-attempt
                // history: the run is opened only after a locked review succeeds.
                if ($review->hasBlockers) {
                    throw DataShareMirrorException::blocked();
                }

                if ($expectedStateToken !== null && ! hash_equals($review->stateToken, $expectedStateToken)) {
                    throw DataShareMirrorException::staleReview();
                }

                $status = $this->status();
                $type = $review->direction === DataShareMirrorDirection::Pull
                    ? DataOperationType::MirrorPull
                    : DataOperationType::MirrorPush;
                $mode = $status->transferMode ?? 'native';
                $reporter->report((string) __('Review remains valid. Transfer mode: :mode.', ['mode' => $mode]));

                return $this->runRecorded($type, $review, $mode, $this->remoteInstanceId(), $reporter);
            });
        } catch (DataShareMirrorException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw DataShareMirrorException::unexpected('execute', $exception, outcomeIndeterminate: true);
        }
    }

    /** @param list<string> $tableNames */
    public function forcePush(array $tableNames, ?string $expectedStateToken = null, ?callable $progress = null): DataShareMirrorExecutionResult
    {
        $reporter = DataShareMirrorProgress::listen($progress);
        $reporter->report((string) __('Waiting for the mirror operation lock.'));

        try {
            return $this->operationLock->run(function () use ($tableNames, $expectedStateToken, $reporter): DataShareMirrorExecutionResult {
                $reporter->report((string) __('Operation lock acquired. Revalidating the reviewed force push.'));
                // Re-review the exact push selection inside the lock. The remote
                // schema can change while waiting for the lock; a stale plan must
                // never drop and recreate tables. Only after a fresh in-lock review
                // and token match is the destructive force transformation applied.
                $review = $this->forceablePushReview(
                    $this->reviewer->review(DataShareMirrorDirection::Push, $tableNames),
                );

                if ($review->hasBlockers) {
                    throw DataShareMirrorException::blocked();
                }

                if ($expectedStateToken !== null && ! hash_equals($review->stateToken, $expectedStateToken)) {
                    throw DataShareMirrorException::staleReview();
                }
                $reporter->report((string) __('Review remains valid. Transfer mode: native.'));

                return $this->runRecorded(
                    DataOperationType::MirrorForcePush,
                    $review,
                    'native',
                    $this->remoteInstanceId(),
                    $reporter,
                );
            });
        } catch (DataShareMirrorException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw DataShareMirrorException::unexpected('force_push', $exception, outcomeIndeterminate: true);
        }
    }

    /**
     * Open a ledger run in-lock, execute the engine, and finalize with strict
     * terminal semantics. An engine that does not confirm completion is recorded
     * as indeterminate — never guessed to have failed — because an external
     * transfer may already have committed at the destination.
     */
    private function runRecorded(
        DataOperationType $type,
        DataShareMirrorReview $review,
        string $mode,
        ?string $remoteInstanceId,
        DataShareMirrorProgress $progress,
    ): DataShareMirrorExecutionResult {
        $localInstanceId = $this->localInstanceId();

        // Push acknowledges only the generation captured for its snapshot: read it
        // before mutation so concurrent commits during the push stay "changed".
        $isPush = $type === DataOperationType::MirrorPush || $type === DataOperationType::MirrorForcePush;
        $capturedGenerations = [];
        if ($isPush) {
            $tracker = app(DataFreshnessTracker::class);
            foreach ($review->items as $item) {
                $capturedGenerations[$item->table] = $tracker->currentGeneration($item->table);
            }
        }

        $runId = $this->operations->open($type->value, array_filter([
            'source' => 'data-share.mirror',
            'direction' => $review->direction->value,
            'transfer_mode' => $mode,
            'is_forced' => $type === DataOperationType::MirrorForcePush,
            'local_instance_id' => $localInstanceId,
            'remote_instance_id' => $remoteInstanceId,
        ], fn (mixed $value): bool => $value !== null));
        $progress->report((string) __('Durable Data Operations run #:id started.', ['id' => $runId]));

        try {
            $result = $this->engines->forMode($mode)->execute($review, $progress);
        } catch (Throwable $exception) {
            // Determinate, rollback-safe/pre-mutation failures are failed; only
            // genuinely uncertain outcomes (or unexpected errors) are indeterminate.
            $determinate = $exception instanceof DataShareMirrorException && ! $exception->outcomeIndeterminate;

            $this->operations->finalize(
                $runId,
                $determinate ? DataOperationStatus::Failed->value : DataOperationStatus::Indeterminate->value,
                [
                    'failure_summary' => $determinate
                        ? 'No destination mutation is known to have committed.'
                        : 'The mirror engine did not confirm completion; the destination may have committed.',
                ],
            );
            $progress->report((string) __('FAILED: The transfer did not confirm completion. The durable run records the known outcome.'));

            throw $exception;
        }

        $canProject = $localInstanceId !== null && $remoteInstanceId !== null;

        // The remote has committed. Record all summaries, observations, and the
        // terminal state as one short Local unit; if any of it fails after the
        // external mutation, the run is best-effort marked indeterminate rather
        // than left running with partial "successful" observations.
        try {
            $progress->report((string) __('Transfer committed. Recording per-table results and observations.'));
            DB::transaction(function () use ($result, $runId, $canProject, $localInstanceId, $remoteInstanceId, $isPush, $capturedGenerations): void {
                foreach ($result->items as $item) {
                    $localRows = $item['local_rows'] ?? null;
                    $remoteRows = $item['remote_rows'] ?? null;

                    $this->operations->recordTable($runId, (string) $item['table'], [
                        'actions' => [$item['action']],
                        'rows_before' => $localRows,
                        'rows_after' => $remoteRows,
                        'range_kind' => DataOperationRangeKind::NotApplicable->value,
                        'terminal_status' => DataOperationStatus::Succeeded->value,
                        'observed_at' => now(),
                    ]);

                    if ($canProject) {
                        app(DataShareMirrorObservationProjection::class)->record(
                            $localInstanceId,
                            $remoteInstanceId,
                            (string) $item['table'],
                            $runId,
                            $localRows,
                            $remoteRows,
                            // Acknowledge exactly the generation captured before the push.
                            $isPush ? ($capturedGenerations[$item['table']] ?? null) : null,
                        );
                    }
                }

                $this->operations->finalize($runId, DataOperationStatus::Succeeded->value);
            });
            $progress->report((string) __('Completed: Durable run #:id recorded successfully.', ['id' => $runId]));
        } catch (Throwable $exception) {
            $this->operations->finalize($runId, DataOperationStatus::Indeterminate->value, [
                'failure_summary' => 'The destination committed, but Local completion bookkeeping did not finish.',
            ]);
            $progress->report((string) __('Warning: The destination committed, but Local completion bookkeeping did not finish.'));

            throw $exception;
        }

        return $result->withRunId($runId > 0 ? $runId : null);
    }

    private function localInstanceId(): ?string
    {
        try {
            return app(DataShareInstanceIdentityResolver::class)->current()->id;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A stable identity for the remote endpoint, so observations from different
     * Supabase projects never overwrite or leak into one another. Derived from
     * the endpoint host + database (not the connection URL or fingerprint, which
     * are never persisted as identity), not the provider adapter key.
     */
    private function remoteInstanceId(): ?string
    {
        try {
            $config = $this->connections->mirror()->getConfig();
            $host = (string) ($config['host'] ?? '');
            $database = (string) ($config['database'] ?? '');

            if ($host !== '' || $database !== '') {
                return 'remote:'.substr(hash('sha256', $host.'/'.$database), 0, 20);
            }
        } catch (Throwable) {
            // Fall back to the provider key only when the endpoint cannot be read.
        }

        try {
            return $this->status()->providerKey;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Record the current Local and remote row counts for the exact selected
     * tables as a clearly labelled retrospective baseline. This never claims to
     * be an original push and never infers an actor from history — it captures
     * what is observably true now, attributed to whoever runs it.
     *
     * @param  list<string>  $tableNames
     * @return int the durable baseline run id
     */
    public function captureBaseline(array $tableNames): int
    {
        try {
            return $this->operationLock->run(function () use ($tableNames): int {
                $review = $this->review('push', $tableNames);

                if ($review->hasBlockers) {
                    throw DataShareMirrorException::blocked();
                }

                $localInstanceId = $this->localInstanceId();
                $remoteInstanceId = $this->remoteInstanceId();

                $runId = $this->operations->open(DataOperationType::MirrorBaseline->value, array_filter([
                    'source' => 'data-share.mirror',
                    'local_instance_id' => $localInstanceId,
                    'remote_instance_id' => $remoteInstanceId,
                ], fn (mixed $value): bool => $value !== null));

                foreach ($review->items as $item) {
                    $localRows = $this->countRows($this->connections->local(), $item->table);
                    $remoteRows = $this->countRows($this->connections->mirror(), $item->table);

                    $this->operations->recordTable($runId, $item->table, [
                        'actions' => ['baseline'],
                        'rows_before' => $localRows,
                        'rows_after' => $remoteRows,
                        'range_kind' => DataOperationRangeKind::NotApplicable->value,
                        'terminal_status' => DataOperationStatus::Succeeded->value,
                        'observed_at' => now(),
                    ]);

                    if ($localInstanceId !== null && $remoteInstanceId !== null) {
                        app(DataShareMirrorObservationProjection::class)->record(
                            $localInstanceId,
                            $remoteInstanceId,
                            $item->table,
                            $runId,
                            $localRows,
                            $remoteRows,
                        );
                    }
                }

                $this->operations->finalize($runId, DataOperationStatus::Succeeded->value);

                return $runId;
            });
        } catch (DataShareMirrorException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw DataShareMirrorException::unexpected('capture_baseline', $exception);
        }
    }

    private function countRows(Connection $connection, string $table): ?int
    {
        try {
            return (int) $connection->table($table)->count();
        } catch (Throwable) {
            return null;
        }
    }

    private function forceablePushReview(DataShareMirrorReview $review): DataShareMirrorReview
    {
        $forceableCodes = ['schema_missing_at_endpoint', 'schema_incompatible'];
        $items = array_map(function (DataShareMirrorReviewItem $item) use ($forceableCodes): DataShareMirrorReviewItem {
            $blockers = array_values(array_filter(
                $item->blockers,
                fn ($blocker): bool => ! in_array($blocker->code, $forceableCodes, true),
            ));

            return new DataShareMirrorReviewItem(
                $item->table,
                $blockers === [] ? $item->intendedAction : DataShareMirrorAction::Blocked,
                $item->intendedAction,
                $blockers,
            );
        }, $review->items);
        $counts = ['create' => 0, 'replace' => 0, 'delete' => 0, 'blocked' => 0];
        foreach ($items as $item) {
            $counts[$item->action->value]++;
        }

        return new DataShareMirrorReview(
            DataShareMirrorDirection::Push,
            $items,
            $counts['blocked'] > 0,
            $counts,
            $review->stateToken,
        );
    }
}
