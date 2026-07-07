<?php

namespace App\Base\Queue\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ActionableFailedJobRepository
{
    private const AI_CHAT_TURN_QUEUE = 'ai-chat-turns';

    private const RUN_CHAT_TURN_JOB = 'App\\Modules\\Core\\AI\\Jobs\\RunChatTurnJob';

    private const CHAT_TURN_OWNER_STALE_MINUTES = 10;

    public function query(): Builder
    {
        $query = DB::table('failed_jobs');
        $hiddenIds = $this->nonActionableChatTurnFailedJobIds();

        if ($hiddenIds !== []) {
            $query->whereNotIn('failed_jobs.id', $hiddenIds);
        }

        return $query;
    }

    public function count(): ?int
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return null;
            }

            return (int) $this->query()->count();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    public function retryableUuids(): array
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return [];
            }

            return $this->query()
                ->pluck('uuid')
                ->map(fn (mixed $uuid): string => (string) $uuid)
                ->filter(fn (string $uuid): bool => $uuid !== '')
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function isRetryableUuid(string $uuid): bool
    {
        if ($uuid === '') {
            return false;
        }

        try {
            if (! Schema::hasTable('failed_jobs')) {
                return false;
            }

            return $this->query()
                ->where('uuid', $uuid)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<int>
     */
    private function nonActionableChatTurnFailedJobIds(): array
    {
        if (! Schema::hasTable('failed_jobs') || ! Schema::hasTable('ai_runs')) {
            return [];
        }

        $runIdsByFailedJobId = $this->chatTurnRunIdsByFailedJobId();

        if ($runIdsByFailedJobId === []) {
            return [];
        }

        $runs = DB::table('ai_runs')
            ->whereIn('id', array_values(array_unique($runIdsByFailedJobId)))
            ->get(['id', 'source', 'status', 'current_phase', 'runtime_meta'])
            ->keyBy('id');

        $hiddenIds = [];

        foreach ($runIdsByFailedJobId as $failedJobId => $runId) {
            $run = $runs->get($runId);

            if (! $this->isActionableChatTurnRun($run)) {
                $hiddenIds[] = (int) $failedJobId;
            }
        }

        return $hiddenIds;
    }

    /**
     * @return array<int, string>
     */
    private function chatTurnRunIdsByFailedJobId(): array
    {
        $jobs = DB::table('failed_jobs')
            ->where('queue', self::AI_CHAT_TURN_QUEUE)
            ->get(['id', 'payload']);

        $runIdsByFailedJobId = [];

        foreach ($jobs as $job) {
            $payload = json_decode((string) $job->payload, true);

            if (! is_array($payload) || data_get($payload, 'data.commandName') !== self::RUN_CHAT_TURN_JOB) {
                continue;
            }

            $runId = $this->extractRunId($payload);

            if ($runId === null || $runId === '') {
                continue;
            }

            $runIdsByFailedJobId[(int) $job->id] = $runId;
        }

        return $runIdsByFailedJobId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractRunId(array $payload): ?string
    {
        $command = data_get($payload, 'data.command');

        if (is_string($command) && preg_match('/s:5:"runId";s:\d+:"([^"]+)";/', $command, $matches) === 1) {
            return $matches[1];
        }

        $displayName = $payload['displayName'] ?? null;

        if (is_string($displayName) && preg_match('/^RunChatTurn\[([^\]]+)]$/', $displayName, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function isActionableChatTurnRun(mixed $run): bool
    {
        if (! is_object($run)) {
            return false;
        }

        return ($run->source ?? null) === 'chat'
            && ($run->status ?? null) === 'queued'
            && ($run->current_phase ?? null) === 'waiting_for_worker'
            && ! $this->hasFreshExecutionOwner($run->runtime_meta ?? null);
    }

    private function hasFreshExecutionOwner(mixed $runtimeMeta): bool
    {
        $meta = is_string($runtimeMeta) ? json_decode($runtimeMeta, true) : $runtimeMeta;

        if (! is_array($meta)) {
            return false;
        }

        $owner = data_get($meta, 'execution_owner');

        if (! is_string($owner) || $owner === '') {
            return false;
        }

        $claimedAt = data_get($meta, 'execution_owner_claimed_at');

        if (! is_string($claimedAt)) {
            return true;
        }

        try {
            return now()->diffInMinutes(Carbon::parse($claimedAt)) < self::CHAT_TURN_OWNER_STALE_MINUTES;
        } catch (\Throwable) {
            return true;
        }
    }
}
