<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use Illuminate\Support\Str;

class LaraOrchestrationService
{
    public function __construct(
        private readonly LaraCapabilityMatcher $capabilityMatcher,
        private readonly LaraTaskDispatcher $taskDispatcher,
    ) {}

    /**
     * Parse and process a delegation message.
     *
     * Contract:
     * - Returns null when message is not a delegation command.
     * - Returns orchestration response payload when command is handled.
     *
     * Supported command format:
     *   /delegate <task description>
     *
     * @return array{assistant_content: string, run_id: string, meta: array<string, mixed>}|null
     */
    public function dispatchFromMessage(string $message): ?array
    {
        $task = $this->extractDelegationTask($message);

        if ($task === null) {
            return null;
        }

        if ($task === '') {
            return $this->response(
                __('Use "/delegate <task>" to delegate work to a Digital Worker.'),
                ['status' => 'invalid_command'],
            );
        }

        $match = $this->capabilityMatcher->matchBestForTask($task);
        if ($match === null) {
            return $this->response(
                __('No delegated Digital Worker is available for this request.'),
                ['status' => 'no_workers'],
            );
        }

        $dispatch = $this->taskDispatcher->dispatchForCurrentUser($match['employee_id'], $task);

        return $this->response(
            __('Delegation queued to :worker (dispatch: :dispatch_id).', [
                'worker' => $dispatch['employee_name'],
                'dispatch_id' => $dispatch['dispatch_id'],
            ]),
            [
                'status' => 'queued',
                'selected_worker' => $match,
                'dispatch' => $dispatch,
            ],
        );
    }

    /**
     * @return array{assistant_content: string, run_id: string, meta: array<string, mixed>}
     */
    private function response(string $assistantContent, array $orchestrationMeta): array
    {
        return [
            'assistant_content' => $assistantContent,
            'run_id' => 'run_'.Str::random(12),
            'meta' => [
                'orchestration' => $orchestrationMeta,
            ],
        ];
    }

    private function extractDelegationTask(string $message): ?string
    {
        $trimmed = trim($message);

        if (! str_starts_with($trimmed, '/delegate')) {
            return null;
        }

        return trim((string) substr($trimmed, strlen('/delegate')));
    }
}
