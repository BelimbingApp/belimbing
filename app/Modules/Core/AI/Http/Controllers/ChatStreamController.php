<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Http\Controllers;

use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\DTO\PageSnapshot;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\ChatRunPersister;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\PageContextHolder;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\TurnEventPublisher;
use App\Modules\Core\AI\Services\TurnStreamBridge;
use App\Modules\Core\AI\Services\Workspace\PromptRenderer;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE endpoint for streaming agent chat responses.
 *
 * The client-side flow is:
 * 1. Livewire prepares the run (persists user message, creates session if needed)
 * 2. Client opens EventSource to this endpoint with the pending run params
 * 3. This controller streams AgenticRuntime events as SSE
 * 4. On 'done', the client finalizes the UI and Livewire persists the assistant message
 */
class ChatStreamController
{
    public function __construct(
        private readonly ChatRunPersister $persister,
        private readonly TurnStreamBridge $bridge,
        private readonly TurnEventPublisher $turnPublisher,
    ) {}

    /**
     * Stream a chat response as Server-Sent Events.
     */
    public function __invoke(Request $request): StreamedResponse|Response
    {
        $employeeId = (int) $request->query('employee_id', (string) Employee::LARA_ID);
        $sessionId = (string) $request->query('session_id', '');
        $modelOverride = $request->query('model') ?: null;

        if ($sessionId === '') {
            return response('Missing session_id', 400);
        }

        $sessionManager = app(SessionManager::class);
        $session = $sessionManager->get($employeeId, $sessionId);

        if ($session === null) {
            return response('Session not found', 404);
        }

        $messageManager = app(MessageManager::class);
        $messages = $messageManager->read($employeeId, $sessionId);

        if ($messages === []) {
            return response('No messages in session', 400);
        }

        $this->resolvePageContext($request);

        [$systemPrompt, $promptMeta] = $this->resolvePromptPackage($employeeId, $messages);

        $runtime = app(AgenticRuntime::class);

        $turn = ChatTurn::query()->create([
            'employee_id' => $employeeId,
            'session_id' => $sessionId,
            'acting_for_user_id' => auth()->id(),
            'status' => TurnStatus::Queued,
            'current_phase' => TurnPhase::WaitingForWorker,
        ]);

        $extraMeta = $promptMeta !== null ? ['prompt_package' => $promptMeta] : [];

        return new StreamedResponse(function () use ($turn, $runtime, $messages, $employeeId, $systemPrompt, $modelOverride, $messageManager, $sessionId, $extraMeta): void {
            try {
                $this->streamAndEmit(
                    turn: $turn,
                    runtime: $runtime,
                    messages: $messages,
                    employeeId: $employeeId,
                    systemPrompt: $systemPrompt,
                    modelOverride: $modelOverride,
                    sessionId: $sessionId,
                );

                $this->persister->materializeFromTurn($turn, $messageManager, $employeeId, $sessionId, $extraMeta);
            } catch (\Throwable $e) {
                $turn->refresh();

                if (! $turn->isTerminal()) {
                    $this->turnPublisher->turnFailed($turn, 'runtime_exception', $e->getMessage());
                }

                // Best-effort transcript materialization on exception
                try {
                    $this->persister->materializeFromTurn($turn->refresh(), $messageManager, $employeeId, $sessionId, $extraMeta);
                } catch (\Throwable) {
                    // Don't mask the original exception
                }

                throw $e;
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Stream runtime events through the bridge and emit as SSE.
     *
     * The bridge yields turn event SSE payloads (same format as the resume
     * endpoint), so the client receives a unified event stream.
     *
     * @param  array<int, object>  $messages
     */
    private function streamAndEmit(
        ChatTurn $turn,
        AgenticRuntime $runtime,
        array $messages,
        int $employeeId,
        ?string $systemPrompt,
        ?string $modelOverride,
        string $sessionId,
    ): void {
        $runtimeStream = $runtime->runStream(
            $messages, $employeeId, $systemPrompt, $modelOverride,
            sessionId: $sessionId, turnId: $turn->id,
        );

        foreach ($this->bridge->wrap($turn, $runtimeStream) as $turnEvent) {
            $this->emitTurnEvent($turnEvent);
        }
    }

    /**
     * @param  array<int, object>  $messages
     * @return array{0: ?string, 1: ?array<string, mixed>}
     */
    private function resolvePromptPackage(int $employeeId, array $messages): array
    {
        if ($employeeId !== Employee::LARA_ID) {
            return [null, null];
        }

        $factory = app(LaraPromptFactory::class);
        $package = $factory->buildPackage($messages[count($messages) - 1]->content ?? '');

        return [
            app(PromptRenderer::class)->render($package),
            $package->describe(),
        ];
    }

    /**
     * Emit a turn event as SSE.
     *
     * Uses the event_type as the SSE event name so the client can listen
     * for specific event types (e.g., `source.addEventListener('tool.started', ...)`).
     *
     * @param  array<string, mixed>  $payload
     */
    private function emitTurnEvent(array $payload): void
    {
        $eventType = $payload['event_type'] ?? 'turn_event';

        echo "event: {$eventType}\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * Hydrate page context from the cache key set by prepareStreamingRun().
     *
     * The Livewire component resolves context on the user's page request
     * (where the route is the actual page, not the SSE endpoint) and
     * caches the serialized payload under a short-lived key. The key is
     * passed as a query param so we can hydrate into the request-scoped
     * PageContextHolder for this streaming request.
     */
    private function resolvePageContext(Request $request): void
    {
        $cacheKey = $request->query('page_ctx');

        if (! is_string($cacheKey) || $cacheKey === '') {
            return;
        }

        $payload = Cache::pull($cacheKey);

        if (! is_array($payload)) {
            return;
        }

        $holder = app(PageContextHolder::class);

        $consentLevel = $payload['consent'] ?? 'page';
        $holder->setConsentLevel($consentLevel);

        if ($consentLevel === 'off') {
            return;
        }

        if (is_array($payload['context'] ?? null)) {
            $holder->setContext(PageContext::fromArray($payload['context']));
        }

        if ($consentLevel === 'full' && is_array($payload['snapshot'] ?? null)) {
            $holder->setSnapshot(PageSnapshot::fromArray($payload['snapshot']));
        }
    }
}
