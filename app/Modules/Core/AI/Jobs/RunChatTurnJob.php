<?php

namespace App\Modules\Core\AI\Jobs;

use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\ChatTurnRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

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
        $turn = AiRun::query()->find($this->runId);

        if ($turn === null || $turn->isTerminal()) {
            return;
        }

        if ($this->isOwnedByStreamInline($turn)) {
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

    private function isOwnedByStreamInline(AiRun $turn): bool
    {
        if (data_get($turn->runtime_meta, 'execution_owner') !== 'stream_inline') {
            return false;
        }

        $claimedAt = data_get($turn->runtime_meta, 'execution_owner_claimed_at');

        if (! is_string($claimedAt)) {
            return true;
        }

        return now()->diffInMinutes(Carbon::parse($claimedAt)) < 10;
    }
}
