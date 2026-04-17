<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Services\ChatRunPersister;
use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\PageContextResolver;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\TurnEventPublisher;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Handles streaming chat run preparation and finalization.
 *
 * Uses direct-streaming architecture: creates a ChatTurn with runtime_meta,
 * returns a stream URL so Alpine can open a persistent fetch connection.
 * The streaming controller runs the agentic runtime inline.
 */
trait HandlesStreaming
{
    private const BOOTING_FORCE_STOP_SECONDS = 30;

    private const STALE_RUNNING_STOP_MINUTES = 30;

    /**
     * Prepare a streaming run: persist user message, create turn, return stream URL.
     *
     * Creates a ChatTurn with runtime_meta containing model override, page
     * context, and execution mode. Returns the turn ID and stream URL so
     * Alpine can open a persistent fetch connection to the streaming controller.
     *
     * @return array{
     *     status: 'started'|'session_busy',
     *     turnId: string,
     *     session_id: string,
     *     streamUrl?: string,
     *     replayUrl: string,
     *     phase: string|null,
     *     label: string|null,
     *     started_at: string|null,
     *     created_at: string|null,
     *     timer_anchor_at: string|null
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

        $turn = ChatTurn::query()->create([
            'employee_id' => $this->employeeId,
            'session_id' => $this->selectedSessionId,
            'acting_for_user_id' => auth()->id(),
            'status' => TurnStatus::Queued,
            'current_phase' => TurnPhase::WaitingForWorker,
            'runtime_meta' => [
                'model_override' => $this->normalizeModelOverride($this->selectedModel),
                'page_context' => $this->resolvePageContextForDispatch(),
                'execution_mode' => 'interactive',
            ],
        ]);

        return [
            'status' => 'started',
            'turnId' => $turn->id,
            'streamUrl' => route('ai.chat.turn.stream', ['turnId' => $turn->id]),
            'replayUrl' => route('ai.chat.turn.events', ['turnId' => $turn->id]),
            'session_id' => $this->selectedSessionId,
            'phase' => TurnPhase::WaitingForWorker->value,
            'label' => TurnPhase::WaitingForWorker->label(),
            'started_at' => $turn->started_at?->toIso8601String(),
            'created_at' => $turn->created_at?->toIso8601String(),
            'timer_anchor_at' => $turn->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array{
     *     turnId: string,
     *     session_id: string,
     *     replayUrl: string,
     *     phase: string|null,
     *     label: string|null,
     *     started_at: string|null,
     *     created_at: string|null,
     *     timer_anchor_at: string|null,
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

        $activeTurns = ChatTurn::query()
            ->where('employee_id', $this->employeeId)
            ->where('acting_for_user_id', $actingForUserId)
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

    private function findActiveTurnForSession(string $sessionId): ?ChatTurn
    {
        $userId = auth()->id();

        if (! is_numeric($userId)) {
            return null;
        }

        $actingForUserId = (int) $userId;

        return ChatTurn::query()
            ->where('employee_id', $this->employeeId)
            ->where('session_id', $sessionId)
            ->where('acting_for_user_id', $actingForUserId)
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
            ]);
    }

    /**
     * @return list<string>
     */
    private function activeTurnStatusValues(): array
    {
        return [
            TurnStatus::Queued->value,
            TurnStatus::Booting->value,
            TurnStatus::Running->value,
        ];
    }

    /**
     * @return array{
     *     turnId: string,
     *     session_id: string,
     *     replayUrl: string,
     *     phase: string|null,
     *     label: string|null,
     *     started_at: string|null,
     *     created_at: string|null,
     *     timer_anchor_at: string|null
     * }
     */
    private function formatActiveTurnPayload(ChatTurn $turn): array
    {
        $phase = $turn->current_phase?->value;
        $label = $turn->current_label ?? $turn->current_phase?->label();
        $startedAt = $turn->started_at?->toIso8601String();
        $createdAt = $turn->created_at?->toIso8601String();

        return [
            'turnId' => $turn->id,
            'session_id' => $turn->session_id,
            'replayUrl' => route('ai.chat.turn.events', ['turnId' => $turn->id]),
            'phase' => $phase,
            'label' => $label,
            'started_at' => $startedAt,
            'created_at' => $createdAt,
            'timer_anchor_at' => $startedAt ?? $createdAt,
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
     * @return array{consent: string, context: array<string, mixed>|null, snapshot: array<string, mixed>|null}|null
     */
    private function resolvePageContextForDispatch(): ?array
    {
        $consentLevel = $this->pageAwareness ?? 'page';

        if ($consentLevel === 'off') {
            return null;
        }

        $resolver = app(PageContextResolver::class);
        $context = $resolver->resolveFromUrl($this->pageUrl);

        if ($context === null) {
            return null;
        }

        $payload = [
            'consent' => $consentLevel,
            'context' => $context->toArray(),
            'snapshot' => null,
        ];

        if ($consentLevel === 'full') {
            $snapshot = $resolver->resolveSnapshotFromUrl($this->pageUrl);
            $payload['snapshot'] = $snapshot?->toArray();
        }

        return $payload;
    }

    /**
     * Finalize a completed streaming run by refreshing component state.
     */
    public function finalizeStreamingRun(?string $turnId = null, ?string $sessionId = null): void
    {
        $this->isLoading = false;

        if (($sessionId === null || $sessionId === '') && is_string($turnId) && $turnId !== '') {
            $sessionId = ChatTurn::query()->whereKey($turnId)->value('session_id');
        }

        if (is_string($turnId) && $turnId !== '' && is_string($sessionId) && $sessionId !== '') {
            $this->dispatch('agent-chat-response-ready', turnId: $turnId, sessionId: $sessionId);
        } elseif (is_string($turnId) && $turnId !== '') {
            $this->dispatch('agent-chat-response-ready', turnId: $turnId);
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
    public function cancelActiveTurn(string $turnId): void
    {
        $turn = ChatTurn::query()->find($turnId);

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

    private function shouldForceStopImmediately(ChatTurn $turn, bool $alreadyOrphaned = false): bool
    {
        if ($alreadyOrphaned) {
            return true;
        }

        return match ($turn->status) {
            TurnStatus::Queued => true,
            TurnStatus::Booting => $turn->current_phase === TurnPhase::WaitingForWorker
                && ($turn->created_at?->lte(now()->subSeconds(self::BOOTING_FORCE_STOP_SECONDS)) ?? false),
            TurnStatus::Running => $turn->started_at?->lte(now()->subMinutes(self::STALE_RUNNING_STOP_MINUTES))
                ?? $turn->created_at?->lte(now()->subMinutes(self::STALE_RUNNING_STOP_MINUTES))
                ?? false,
            default => false,
        };
    }

    private function forceStopTurn(ChatTurn $turn): void
    {
        if ($turn->isTerminal()) {
            return;
        }

        app(TurnEventPublisher::class)->turnCancelled($turn, 'User cancelled stale turn');
        $this->markCurrentRunCancelled($turn->current_run_id);

        app(ChatRunPersister::class)->materializeFromTurn(
            $turn->refresh(),
            app(MessageManager::class),
            (int) $turn->employee_id,
            (string) $turn->session_id,
        );

        $this->dispatch('agent-chat-response-ready', turnId: $turn->id, sessionId: $turn->session_id);

        if ($this->selectedSessionId !== null && $this->selectedSessionId === $turn->session_id) {
            $this->dispatch('agent-chat-focus-composer');
        }
    }

    private function markCurrentRunCancelled(?string $runId): void
    {
        if (! is_string($runId) || $runId === '') {
            return;
        }

        $run = AiRun::query()->find($runId);

        if ($run === null || $run->status !== AiRunStatus::Running) {
            return;
        }

        $run->status = AiRunStatus::Cancelled;
        $run->finished_at = now();

        if ($run->started_at !== null && $run->latency_ms === null) {
            $run->latency_ms = max(0, $run->started_at->diffInMilliseconds($run->finished_at));
        }

        $run->save();
    }
}
