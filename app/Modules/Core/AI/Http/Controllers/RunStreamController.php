<?php

namespace App\Modules\Core\AI\Http\Controllers;

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\ChatTurnRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RunStreamController
{
    public function __construct(
        private readonly ChatTurnRunner $runner,
    ) {}

    public function __invoke(Request $request, string $runId): StreamedResponse
    {
        $turn = AiRun::query()->find($runId);

        if ($turn === null) {
            return $this->errorStream(404, 'Run not found');
        }

        if ((int) $turn->acting_for_user_id !== (int) auth()->id()) {
            return $this->errorStream(403, 'Forbidden');
        }

        if ($turn->source !== 'chat') {
            return $this->errorStream(409, 'Run is not a chat turn');
        }

        return response()->stream(function () use ($turn): void {
            if ($this->tryRunQueuedTurnInline($turn)) {
                return;
            }

            $this->writeObservedTurnStream($turn);
        }, 200, $this->streamHeaders());
    }

    private function tryRunQueuedTurnInline(AiRun $turn): bool
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $claimedTurn = $this->claimQueuedTurnForInlineExecution($turn->id);

        if ($claimedTurn === null) {
            return false;
        }

        $this->runner->run($claimedTurn, function (array $payload): void {
            echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            $this->flushOutput();
        });

        if (! connection_aborted()) {
            echo json_encode(['_stream_complete' => true], JSON_THROW_ON_ERROR)."\n";
            $this->flushOutput();
        }

        return true;
    }

    private function claimQueuedTurnForInlineExecution(string $runId): ?AiRun
    {
        return DB::transaction(function () use ($runId): ?AiRun {
            $turn = AiRun::query()
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();

            if (
                $turn === null
                || $turn->source !== 'chat'
                || $turn->status !== AiRunStatus::Queued
                || $turn->current_phase !== RunPhase::WaitingForWorker
            ) {
                return null;
            }

            $turn->runtime_meta = array_merge($turn->runtime_meta ?? [], [
                'execution_owner' => 'stream_inline',
                'execution_owner_claimed_at' => now()->toIso8601String(),
            ]);
            $turn->save();

            return $turn;
        });
    }

    private function writeObservedTurnStream(AiRun $turn): void
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $lastSeq = 0;

        while (true) {
            if (connection_aborted()) {
                return;
            }

            $turn->refresh();

            foreach ($turn->eventsAfter($lastSeq)->get() as $event) {
                $lastSeq = $event->seq;
                echo json_encode($event->toSsePayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                $this->flushOutput();
            }

            if ($turn->status->isTerminal()) {
                if (! connection_aborted()) {
                    echo json_encode(['_stream_complete' => true], JSON_THROW_ON_ERROR)."\n";
                    $this->flushOutput();
                }

                return;
            }

            usleep(250_000);
        }
    }

    private function errorStream(int $status, string $message): StreamedResponse
    {
        return response()->stream(function () use ($message): void {
            echo json_encode(['error' => $message], JSON_THROW_ON_ERROR)."\n";
            $this->flushOutput();
        }, $status, $this->streamHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function streamHeaders(): array
    {
        return [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
