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
use Illuminate\Contracts\View\View;
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
     * Returned as view data (not a public property) because Message DTOs
     * contain DateTimeImmutable which Livewire cannot dehydrate.
     *
     * @return list<Message>
     */
    private function loadTranscript(): array
    {
        if ($this->runSessionId === null) {
            return [];
        }

        $allMessages = app(MessageManager::class)->read($this->runEmployeeId, $this->runSessionId);

        return array_values(array_filter(
            $allMessages,
            fn (Message $msg) => $msg->runId === $this->runId
                || $msg->type === 'thinking'
                || $msg->type === 'tool_call'
                || $msg->type === 'tool_result',
        ));
    }
}
