<?php

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\DTO\PageSnapshot;
use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\ExecutionMode;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Jobs\RunChatTurnJob;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\ChatRunPersister;
use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\PageContextResolver;
use App\Modules\Core\AI\Services\RunEventPublisher;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Handles streaming chat run preparation and finalization.
 *
 * Uses queue-backed streaming architecture: creates an AiRun with runtime_meta,
 * dispatches a worker job, and returns a replay URL so Alpine can poll durable
 * events while the queue owns execution.
 */
trait HandlesStreaming
{
    private const BOOTING_FORCE_STOP_SECONDS = 30;

    private const STALE_RUNNING_STOP_MINUTES = 30;

    /**
     * Prepare a streaming run: persist user message, create turn, return event URLs.
     *
     * Creates an AiRun with runtime_meta containing model override, page
     * context, and execution mode. Returns the turn ID and replay URL so
     * Alpine can observe durable run events without holding a long HTTP request.
     *
     * @return array{
     *     status: 'started'|'session_busy',
     *     runId: string,
     *     session_id: string,
     *     streamUrl?: string,
     *     replayUrl: string,
     *     phase: string|null,
     *     label: string|null,
     *     started_at: string|null,
     *     created_at: string|null,
     *     timer_anchor_at: string|null,
     *     cancel_requested_at: string|null
     * }|null Null when an orchestration shortcut handled the message or input was invalid
     */
    public function prepareStreamingRun(): ?array
    {
        $hasAttachments = $this->attachments !== [] && $this->canAttachFiles();
        $hasText = trim($this->messageInput) !== '';

        if (! $this->isAgentActivated() || (! $hasText && ! $hasAttachments)) {
            return null;
        }

        $sessionManager = app(SessionManager::class);
        if (
            $this->selectedSessionId === null
            || $sessionManager->get($this->employeeId, $this->selectedSessionId) === null
        ) {
            // Recover gracefully when client-side storage points to a stale session ID.
            $session = $sessionManager->create($this->employeeId);
            $this->selectedSessionId = $session->id;
        }

        $activeTurn = $this->findActiveTurnForSession($this->selectedSessionId);
        if ($activeTurn !== null) {
            return [
                'status' => 'session_busy',
                ...$this->formatActiveTurnPayload($activeTurn),
            ];
        }

        $content = trim($this->messageInput);
        $this->messageInput = '';

        $attachmentMeta = $hasAttachments
            ? $this->processAttachments($this->selectedSessionId)
            : [];
        $this->attachments = [];

        $userMeta = $attachmentMeta !== [] ? ['attachments' => $attachmentMeta] : [];

        if ($this->tryLaraOrchestrationShortcut($content, $userMeta, $hasAttachments)) {
            return null;
        }

        $messageManager = app(MessageManager::class);
        $messageManager->appendUserMessage($this->employeeId, $this->selectedSessionId, $content, $userMeta);

        $turn = AiRun::query()->create([
            'employee_id' => $this->employeeId,
            'session_id' => $this->selectedSessionId,
            'acting_for_user_id' => auth()->id(),
            'source' => 'chat',
            'execution_mode' => ExecutionMode::Interactive->value,
            'status' => AiRunStatus::Queued,
            'current_phase' => RunPhase::WaitingForWorker,
            'runtime_meta' => [
                'model_override' => $this->normalizeModelOverride($this->selectedModel),
                'page_context' => $this->resolvePageContextForDispatch(),
                'execution_mode' => 'interactive',
            ],
        ]);

        RunChatTurnJob::dispatch($turn->id);

        return [
            'status' => 'started',
            'runId' => $turn->id,
            'streamUrl' => route('ai.chat.turn.stream', ['runId' => $turn->id]),
            'replayUrl' => route('ai.chat.turn.events', ['runId' => $turn->id]),
            'session_id' => $this->selectedSessionId,
            'phase' => RunPhase::WaitingForWorker->value,
            'label' => RunPhase::WaitingForWorker->label(),
            'started_at' => $turn->started_at?->toIso8601String(),
            'created_at' => $turn->created_at?->toIso8601String(),
            'timer_anchor_at' => $turn->created_at?->toIso8601String(),
            'cancel_requested_at' => null,
        ];
    }

    /**
     * @return array<string, array{
     *     runId: string,
     *     session_id: string,
     *     replayUrl: string,
     *     phase: string|null,
     *     label: string|null,
     *     started_at: string|null,
     *     created_at: string|null,
     *     timer_anchor_at: string|null,
     *     cancel_requested_at: string|null,
     *     status: string
     * }>
     */
    private function activeTurnsBySessionForCurrentUser(): array
    {
        $userId = auth()->id();

        if (! is_numeric($userId)) {
            return [];
        }

        $actingForUserId = (int) $userId;

        $activeTurns = AiRun::query()
            ->where('employee_id', $this->employeeId)
            ->where('acting_for_user_id', $actingForUserId)
            ->where('source', 'chat')
            ->whereIn('status', $this->activeTurnStatusValues())
            ->orderByDesc('created_at')
            ->get([
                'id',
                'session_id',
                'status',
                'current_phase',
                'current_label',
                'started_at',
                'created_at',
                'cancel_requested_at',
            ]);

        $bySession = [];

        foreach ($activeTurns as $turn) {
            if (isset($bySession[$turn->session_id])) {
                continue;
            }

            $bySession[$turn->session_id] = [
                ...$this->formatActiveTurnPayload($turn),
                'status' => $turn->status->value,
            ];
        }

        return $bySession;
    }

    private function findActiveTurnForSession(string $sessionId): ?AiRun
    {
        $userId = auth()->id();

        if (! is_numeric($userId)) {
            return null;
        }

        $actingForUserId = (int) $userId;

        return AiRun::query()
            ->where('employee_id', $this->employeeId)
            ->where('session_id', $sessionId)
            ->where('acting_for_user_id', $actingForUserId)
            ->where('source', 'chat')
            ->whereIn('status', $this->activeTurnStatusValues())
            ->orderByDesc('created_at')
            ->first([
                'id',
                'session_id',
                'status',
                'current_phase',
                'current_label',
                'started_at',
                'created_at',
                'cancel_requested_at',
            ]);
    }

    /**
     * @return list<string>
     */
    private function activeTurnStatusValues(): array
    {
        return [
            AiRunStatus::Queued->value,
            AiRunStatus::Booting->value,
            AiRunStatus::Running->value,
        ];
    }

    /**
     * @return array{
     *     runId: string,
     *     session_id: string,
     *     replayUrl: string,
     *     phase: string|null,
     *     label: string|null,
     *     started_at: string|null,
     *     created_at: string|null,
     *     timer_anchor_at: string|null,
     *     cancel_requested_at: string|null
     * }
     */
    private function formatActiveTurnPayload(AiRun $turn): array
    {
        $phase = $turn->current_phase?->value;
        $label = $turn->current_label ?? $turn->current_phase?->label();
        $startedAt = $turn->started_at?->toIso8601String();
        $createdAt = $turn->created_at?->toIso8601String();

        return [
            'runId' => $turn->id,
            'session_id' => $turn->session_id,
            'replayUrl' => route('ai.chat.turn.events', ['runId' => $turn->id]),
            'phase' => $phase,
            'label' => $label,
            'started_at' => $startedAt,
            'created_at' => $createdAt,
            'timer_anchor_at' => $startedAt ?? $createdAt,
            'cancel_requested_at' => $turn->cancel_requested_at?->toIso8601String(),
        ];
    }

    /**
     * Lara-only sync orchestration paths that bypass streaming.
     *
     * @param  array<string, mixed>  $userMeta
     * @return bool True when the shortcut handled the message (caller returns null)
     */
    private function tryLaraOrchestrationShortcut(string $content, array $userMeta, bool $hasAttachments): bool
    {
        if ($this->employeeId !== Employee::LARA_ID || $hasAttachments) {
            return false;
        }

        $orchestration = app(LaraOrchestrationService::class)->dispatchFromMessage(
            $content,
            $this->selectedSessionId,
        );
        if ($orchestration === null) {
            return false;
        }

        $messageManager = app(MessageManager::class);
        $messageManager->appendUserMessage($this->employeeId, $this->selectedSessionId, $content, $userMeta);

        $messageManager->appendAssistantMessage(
            $this->employeeId,
            $this->selectedSessionId,
            $orchestration['assistant_content'],
            $orchestration['run_id'],
            $orchestration['meta'],
        );

        $this->lastRunMeta = [
            'run_id' => $orchestration['run_id'],
            ...$orchestration['meta'],
        ];

        $this->dispatch('agent-chat-response-ready');
        $this->dispatch('agent-chat-focus-composer');

        $navigationUrl = $orchestration['meta']['orchestration']['navigation']['url'] ?? null;
        if (is_string($navigationUrl) && str_starts_with($navigationUrl, '/')) {
            $this->dispatch('agent-chat-execute-js', js: "Livewire.navigate('".$navigationUrl."')");
        }

        return true;
    }

    /**
     * Resolve page context from the current request for storage in turn runtime_meta.
     *
     * The streaming controller runs in a separate HTTP request whose route is
     * not the user's page. We resolve here (on the real page request) and embed
     * the result in the turn's runtime_meta so the runner can hydrate context.
     *
     * @return array{context: array<string, mixed>|null, snapshot: array<string, mixed>|null}|null
     */
    private function resolvePageContextForDispatch(): ?array
    {
        $resolver = app(PageContextResolver::class);
        $context = $resolver->resolveFromUrl($this->pageUrl);

        if ($context === null) {
            $this->activePageSnapshot = null;

            return null;
        }

        $payload = [
            'context' => $context->toArray(),
            'snapshot' => null,
        ];

        $snapshot = $resolver->resolveSnapshotFromUrl($this->pageUrl);
        $payload['snapshot'] = $this->validatedActivePageSnapshot($context)
            ?? $snapshot?->toArray();

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validatedActivePageSnapshot(PageContext $context): ?array
    {
        if (! is_array($this->activePageSnapshot)) {
            return null;
        }

        try {
            $snapshot = [
                ...$this->activePageSnapshot,
                'page' => $context->toArray(),
            ];

            PageSnapshot::fromArray($snapshot);

            return $snapshot;
        } catch (\Throwable) {
            return null;
        } finally {
            $this->activePageSnapshot = null;
        }
    }

    /**
     * Finalize a completed streaming run by refreshing component state.
     */
    public function finalizeStreamingRun(?string $runId = null, ?string $sessionId = null): void
    {
        $this->isLoading = false;

        if (($sessionId === null || $sessionId === '') && is_string($runId) && $runId !== '') {
            $sessionId = AiRun::query()->whereKey($runId)->value('session_id');
        }

        if (is_string($runId) && $runId !== '' && is_string($sessionId) && $sessionId !== '') {
            $this->dispatch('agent-chat-response-ready', runId: $runId, sessionId: $sessionId);
        } elseif (is_string($runId) && $runId !== '') {
            $this->dispatch('agent-chat-response-ready', runId: $runId);
        } elseif (is_string($sessionId) && $sessionId !== '') {
            $this->dispatch('agent-chat-response-ready', sessionId: $sessionId);
        } else {
            $this->dispatch('agent-chat-response-ready');
        }

        if ($this->selectedSessionId !== null && $this->selectedSessionId === $sessionId) {
            $this->dispatch('agent-chat-focus-composer');
        }
    }

    /**
     * Cancel the active turn for the current user and session.
     *
     * Called by the UI stop button. Sets cancel_requested_at on the turn
     * so the streaming controller's runner detects it cooperatively on
     * the next event iteration.
     */
    public function cancelActiveTurn(string $runId): void
    {
        $turn = AiRun::query()->find($runId);

        if ($turn === null || $turn->isTerminal()) {
            return;
        }

        if ((int) $turn->acting_for_user_id !== (int) auth()->id()) {
            return;
        }

        $wasAlreadyCancelledOrDisconnected = $turn->isCancelRequested();
        $turn->requestCancel('User pressed stop');

        if ($this->shouldForceStopImmediately($turn->refresh(), $wasAlreadyCancelledOrDisconnected)) {
            $this->forceStopTurn($turn->refresh());
        }

        $this->isLoading = false;
    }

    private function shouldForceStopImmediately(AiRun $turn, bool $alreadyOrphaned = false): bool
    {
        if ($alreadyOrphaned) {
            return true;
        }

        return match ($turn->status) {
            AiRunStatus::Queued => true,
            AiRunStatus::Booting => $turn->current_phase === RunPhase::WaitingForWorker
                && ($turn->created_at?->lte(now()->subSeconds(self::BOOTING_FORCE_STOP_SECONDS)) ?? false),
            AiRunStatus::Running => $turn->started_at?->lte(now()->subMinutes(self::STALE_RUNNING_STOP_MINUTES))
                ?? $turn->created_at?->lte(now()->subMinutes(self::STALE_RUNNING_STOP_MINUTES))
                ?? false,
            default => false,
        };
    }

    private function forceStopTurn(AiRun $turn): void
    {
        if ($turn->isTerminal()) {
            return;
        }

        app(RunEventPublisher::class)->turnCancelled($turn, 'User cancelled stale turn');

        app(ChatRunPersister::class)->materializeFromTurn(
            $turn->refresh(),
            app(MessageManager::class),
            (int) $turn->employee_id,
            (string) $turn->session_id,
        );

        $this->dispatch('agent-chat-response-ready', runId: $turn->id, sessionId: $turn->session_id);

        if ($this->selectedSessionId !== null && $this->selectedSessionId === $turn->session_id) {
            $this->dispatch('agent-chat-focus-composer');
        }
    }
}
