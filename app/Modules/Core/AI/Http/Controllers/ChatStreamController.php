<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Http\Controllers;

use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\Workspace\PromptRenderer;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
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

        [$systemPrompt, $promptMeta] = $this->resolvePromptPackage($employeeId, $messages);

        $runtime = app(AgenticRuntime::class);

        return new StreamedResponse(function () use ($runtime, $messages, $employeeId, $systemPrompt, $modelOverride, $messageManager, $sessionId, $promptMeta): void {
            [$fullContent, $runId, $meta, $hadError] = $this->streamRuntimeEvents(
                runtime: $runtime,
                messages: $messages,
                employeeId: $employeeId,
                systemPrompt: $systemPrompt,
                modelOverride: $modelOverride,
                messageManager: $messageManager,
                sessionId: $sessionId,
            );

            if (! $hadError) {
                $effectiveMeta = $meta ?? [];

                if ($promptMeta !== null) {
                    $effectiveMeta['prompt_package'] = $promptMeta;
                }

                $this->persistAssistantMessage($messageManager, $employeeId, $sessionId, $fullContent, $runId, $effectiveMeta);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<int, object>  $messages
     * @return array{0: ?string, 1: ?string, 2: ?array<string, mixed>, 3: bool}
     */
    private function streamRuntimeEvents(
        AgenticRuntime $runtime,
        array $messages,
        int $employeeId,
        ?string $systemPrompt,
        ?string $modelOverride,
        MessageManager $messageManager,
        string $sessionId,
    ): array {
        $fullContent = null;
        $runId = null;
        $meta = null;
        $hadError = false;

        foreach ($runtime->runStream($messages, $employeeId, $systemPrompt, $modelOverride) as $event) {
            $eventName = $event['event'];
            $data = $event['data'];

            $this->emitEvent($eventName, $data);

            if ($eventName === 'done') {
                [$fullContent, $runId, $meta] = $this->captureDoneEvent($data);

                continue;
            }

            if ($eventName === 'error') {
                $hadError = true;
                $this->persistStructuredError($messageManager, $employeeId, $sessionId, $data);
            }
        }

        return [$fullContent, $runId, $meta, $hadError];
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
     * @param  array<string, mixed>  $data
     */
    private function emitEvent(string $eventName, array $data): void
    {
        echo "event: {$eventName}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?string, 1: ?string, 2: array<string, mixed>}
     */
    private function captureDoneEvent(array $data): array
    {
        return [
            $data['content'] ?? '',
            $data['run_id'] ?? null,
            is_array($data['meta'] ?? null) ? $data['meta'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistStructuredError(
        MessageManager $messageManager,
        int $employeeId,
        string $sessionId,
        array $data,
    ): void {
        $errorMeta = is_array($data['meta'] ?? null)
            ? $data['meta']
            : ['message_type' => 'error'];

        $this->persistErrorMessage(
            $messageManager,
            $employeeId,
            $sessionId,
            $data['message'] ?? __('An unexpected error occurred. Please try again.'),
            $data['run_id'] ?? 'run_'.Str::random(12),
            $errorMeta,
        );
    }

    /**
     * Append the assistant message to the session after streaming completes.
     *
     * No-op when the stream ended without a 'done' event (fullContent or runId is null).
     */
    private function persistAssistantMessage(
        MessageManager $messageManager,
        int $employeeId,
        string $sessionId,
        ?string $fullContent,
        ?string $runId,
        array $meta,
    ): void {
        if ($fullContent !== null && $runId !== null) {
            $messageManager->appendAssistantMessage($employeeId, $sessionId, $fullContent, $runId, $meta);
        }
    }

    /**
     * Persist a structured error as an assistant message with error metadata.
     */
    private function persistErrorMessage(
        MessageManager $messageManager,
        int $employeeId,
        string $sessionId,
        string $errorMessage,
        string $runId,
        array $meta,
    ): void {
        $messageManager->appendAssistantMessage(
            $employeeId,
            $sessionId,
            __('⚠ :detail', ['detail' => $errorMessage]),
            $runId,
            $meta,
        );
    }
}
