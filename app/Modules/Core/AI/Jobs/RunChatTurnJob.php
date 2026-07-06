<?php

namespace App\Modules\Core\AI\Jobs;

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\ChatTurnRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Queue job that executes a persisted interactive chat turn.
 *
 * The browser no longer owns run execution. It creates the AiRun and then
 * observes ai_run_events; this job owns the runtime stream and transcript
 * materialization so another browser can attach while the run continues.
 */
class RunChatTurnJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'ai-chat-turns';

    private const EXECUTION_OWNER = 'queue_worker';

    private const EXECUTION_OWNER_STALE_MINUTES = 10;

    public int $timeout = 600;

    public function __construct(
        public string $runId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function displayName(): string
    {
        return 'RunChatTurn['.$this->runId.']';
    }

    public function handle(ChatTurnRunner $runner): void
    {
        $turn = $this->claimQueuedTurn();

        if ($turn === null) {
            return;
        }

        if ($turn->acting_for_user_id !== null) {
            Auth::loginUsingId($turn->acting_for_user_id);
        }

        try {
            $runner->run($turn);
        } finally {
            Auth::logout();
        }
    }

    private function claimQueuedTurn(): ?AiRun
    {
        return DB::transaction(function (): ?AiRun {
            $turn = AiRun::query()
                ->whereKey($this->runId)
                ->lockForUpdate()
                ->first();

            if (
                $turn === null
                || $turn->source !== 'chat'
                || $turn->status !== AiRunStatus::Queued
                || $turn->current_phase !== RunPhase::WaitingForWorker
                || $this->hasFreshExecutionOwner($turn)
            ) {
                return null;
            }

            $turn->runtime_meta = array_merge($turn->runtime_meta ?? [], [
                'execution_owner' => self::EXECUTION_OWNER,
                'execution_owner_claimed_at' => now()->toIso8601String(),
            ]);
            $turn->save();

            return $turn;
        });
    }

    private function hasFreshExecutionOwner(AiRun $turn): bool
    {
        $owner = data_get($turn->runtime_meta, 'execution_owner');

        if (! is_string($owner) || $owner === '') {
            return false;
        }

        $claimedAt = data_get($turn->runtime_meta, 'execution_owner_claimed_at');

        if (! is_string($claimedAt)) {
            return true;
        }

        try {
            return now()->diffInMinutes(Carbon::parse($claimedAt)) < self::EXECUTION_OWNER_STALE_MINUTES;
        } catch (\Throwable) {
            return true;
        }
    }
}
