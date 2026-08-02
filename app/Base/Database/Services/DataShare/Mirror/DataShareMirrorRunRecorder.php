<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorProgress;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReview;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorRunCompletion;
use App\Base\Database\Enums\DataOperationRangeKind;
use App\Base\Database\Enums\DataOperationStatus;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Services\DataShare\Freshness\DataFreshnessTracker;
use App\Base\Foundation\Contracts\DataOperationRecorder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DataShareMirrorRunRecorder
{
    public function __construct(private readonly DataOperationRecorder $operations) {}

    /** @return array<string, int> */
    public function captureGenerations(DataShareMirrorReview $review, bool $isPush): array
    {
        if (! $isPush) {
            return [];
        }

        $tracker = app(DataFreshnessTracker::class);
        $generations = [];
        foreach ($review->items as $item) {
            $generations[$item->table] = $tracker->currentGeneration($item->table);
        }

        return $generations;
    }

    public function recordEngineFailure(int $runId, Throwable $exception, DataShareMirrorProgress $progress): void
    {
        $determinate = $exception instanceof DataShareMirrorException && ! $exception->outcomeIndeterminate;
        $this->operations->finalize($runId, $determinate ? DataOperationStatus::Failed->value : DataOperationStatus::Indeterminate->value, [
            'failure_summary' => $determinate ? 'No destination mutation is known to have committed.' : 'The mirror engine did not confirm completion; the destination may have committed.',
        ]);
        $progress->report((string) ($determinate
            ? __('FAILED: The transfer stopped before the destination commit. The durable run records the failure.')
            : __('FAILED: The transfer did not confirm completion. The durable run records the indeterminate outcome.')));
    }

    public function recordSuccessfulRun(DataShareMirrorRunCompletion $completion): void
    {
        DB::transaction(function () use ($completion): void {
            foreach ($completion->result->items as $item) {
                $localRows = $item['local_rows'] ?? null;
                $remoteRows = $item['remote_rows'] ?? null;

                $this->operations->recordTable($completion->runId, (string) $item['table'], [
                    'actions' => [$completion->expectedManifest[$item['table']]],
                    'rows_before' => $localRows,
                    'rows_after' => $remoteRows,
                    'range_kind' => DataOperationRangeKind::NotApplicable->value,
                    'terminal_status' => DataOperationStatus::Succeeded->value,
                    'observed_at' => now(),
                ]);

                if ($completion->localInstanceId !== null && $completion->remoteInstanceId !== null) {
                    app(DataShareMirrorObservationProjection::class)->record(
                        $completion->localInstanceId,
                        $completion->remoteInstanceId,
                        (string) $item['table'],
                        $completion->runId,
                        $localRows,
                        $remoteRows,
                        $completion->isPush ? ($completion->capturedGenerations[$item['table']] ?? null) : null,
                    );
                }
            }

            $this->operations->finalize($completion->runId, DataOperationStatus::Succeeded->value);
        });
    }
}
