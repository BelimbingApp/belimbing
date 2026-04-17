<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
//
// Standalone run detail page — deep-linkable view of a single AI run.

namespace App\Modules\Core\AI\Livewire;

use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\ControlPlane\RunInspectionService;
use App\Modules\Core\AI\Services\MessageManager;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RunDetail extends Component
{
    public string $runId;

    /** @var array<string, mixed>|null */
    public ?array $inspection = null;

    /** Employee ID for the run (used to reload transcript in render). */
    private int $runEmployeeId = 0;

    /** Session ID for the run (used to reload transcript in render). */
    private ?string $runSessionId = null;

    /**
     * Mount the component with a run ID from the route.
     *
     * Verifies the run exists and the current user owns it (via employee_id).
     */
    public function mount(string $runId): void
    {
        $this->runId = $runId;

        $run = AiRun::query()->find($runId);

        if ($run === null) {
            throw new NotFoundHttpException(__('Run not found.'));
        }

        $employeeId = auth()->user()?->employee?->id;

        if ($employeeId === null || $run->employee_id !== $employeeId) {
            throw new NotFoundHttpException(__('Run not found.'));
        }

        $inspection = app(RunInspectionService::class)->inspectRun($runId);
        $this->inspection = $inspection?->toArray();
        $this->runEmployeeId = $run->employee_id;
        $this->runSessionId = $run->session_id;
    }

    public function render(): View
    {
        return view('livewire.admin.ai.run-detail', [
            'transcript' => $this->loadTranscript(),
        ]);
    }

    /**
     * Load transcript entries for this run's session, filtered to just this run.
     *
     * Falls back to synthesizing entries from ai_runs.tool_actions when
     * the JSONL transcript has no typed entries for this run.
     *
     * Returned as view data (not a public property) because Message DTOs
     * contain DateTimeImmutable which Livewire cannot dehydrate.
     *
     * @return list<Message>
     */
    private function loadTranscript(): array
    {
        $entries = [];

        if ($this->runSessionId !== null) {
            $allMessages = app(MessageManager::class)->read($this->runEmployeeId, $this->runSessionId);

            $entries = array_values(array_filter(
                $allMessages,
                fn (Message $msg) => $msg->runId === $this->runId,
            ));
        }

        $hasTypedEntries = array_filter(
            $entries,
            fn (Message $msg) => in_array($msg->type, ['thinking', 'tool_use'], true),
        ) !== [];

        if (! $hasTypedEntries) {
            $run = AiRun::query()->find($this->runId);
            if ($run !== null) {
                $entries = array_merge($entries, $this->synthesizeFromToolActions($run));
            }
        }

        return $entries;
    }

    /**
     * Synthesize transcript entries from ai_runs.tool_actions.
     *
     * @return list<Message>
     */
    private function synthesizeFromToolActions(AiRun $run): array
    {
        $actions = $run->tool_actions ?? [];
        if ($actions === []) {
            return [];
        }

        $ts = $run->started_at ?? $run->created_at ?? now();
        $messages = [];

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $messages[] = new Message(
                role: 'assistant',
                content: '',
                timestamp: new DateTimeImmutable($ts->toIso8601String()),
                runId: $run->id,
                meta: [
                    'tool' => (string) ($action['tool'] ?? $action['name'] ?? 'unknown'),
                    'args_summary' => $this->buildArgsSummary($action),
                    'status' => isset($action['error_payload']) ? 'error' : 'success',
                    'result_preview' => (string) ($action['result_preview'] ?? ''),
                    'result_length' => isset($action['result_length']) ? (int) $action['result_length'] : 0,
                    'error_payload' => is_array($action['error_payload'] ?? null) ? $action['error_payload'] : null,
                    'synthesized' => true,
                ],
                type: 'tool_use',
            );
        }

        return $messages;
    }

    /**
     * Build a truncated args summary string from a tool action's arguments.
     */
    private function buildArgsSummary(array $action): string
    {
        if (isset($action['args_summary'])) {
            return (string) $action['args_summary'];
        }

        if (isset($action['arguments']) && is_array($action['arguments'])) {
            return Str::limit(
                json_encode($action['arguments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                200,
            );
        }

        return '';
    }
}
