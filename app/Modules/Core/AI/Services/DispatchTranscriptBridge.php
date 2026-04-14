<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Mirrors async dispatch outcomes back into Lara's chat transcript.
 *
 * Slash-command delegation queues background work, but the user still
 * expects the final outcome to appear in the same Lara session. This
 * bridge appends a terminal assistant follow-up when a queued dispatch
 * tied to a Lara session succeeds or fails.
 */
class DispatchTranscriptBridge
{
    public function __construct(
        private readonly MessageManager $messageManager,
    ) {}

    public function appendSucceeded(OperationDispatch $dispatch): void
    {
        $sessionId = $this->sessionId($dispatch);

        if ($sessionId === null) {
            return;
        }

        $summary = trim((string) $dispatch->result_summary);
        $content = __(':target completed the delegated task.', [
            'target' => $this->targetLabel($dispatch),
        ]);

        if ($summary !== '') {
            $content .= "\n\n".$summary;
        }

        $this->messageManager->appendAssistantMessage(
            Employee::LARA_ID,
            $sessionId,
            $content,
            $dispatch->run_id,
        );
    }

    public function appendFailed(OperationDispatch $dispatch, string $errorMessage): void
    {
        $sessionId = $this->sessionId($dispatch);

        if ($sessionId === null) {
            return;
        }

        $content = __(':target could not complete the delegated task.', [
            'target' => $this->targetLabel($dispatch),
        ]);

        $errorMessage = trim($errorMessage);
        if ($errorMessage !== '') {
            $content .= "\n\n".$errorMessage;
        }

        $this->messageManager->appendAssistantMessage(
            Employee::LARA_ID,
            $sessionId,
            $content,
        );
    }

    private function sessionId(OperationDispatch $dispatch): ?string
    {
        $sessionId = data_get($dispatch->meta, 'session_id');

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    private function targetLabel(OperationDispatch $dispatch): string
    {
        $taskProfileLabel = data_get($dispatch->meta, 'task_profile_label');
        if (is_string($taskProfileLabel) && $taskProfileLabel !== '') {
            return __('Lara :task', ['task' => $taskProfileLabel]);
        }

        $employeeName = data_get($dispatch->meta, 'employee_name');
        if (is_string($employeeName) && $employeeName !== '') {
            return $employeeName;
        }

        if ($dispatch->employee_id === Employee::LARA_ID) {
            return 'Lara';
        }

        return __('Agent #:id', ['id' => $dispatch->employee_id ?? 0]);
    }
}
