<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Http\Controllers;

use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SSE endpoint for streaming DW chat responses.
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

        $systemPrompt = $employeeId === Employee::LARA_ID
            ? app(LaraPromptFactory::class)->buildForCurrentUser($messages[count($messages) - 1]->content ?? '')
            : null;

        $runtime = app(AgenticRuntime::class);

        return new StreamedResponse(function () use ($runtime, $messages, $employeeId, $systemPrompt, $modelOverride, $messageManager, $sessionId) {
            $fullContent = null;
            $runId = null;
            $meta = null;

            foreach ($runtime->runStream($messages, $employeeId, $systemPrompt, $modelOverride) as $event) {
                $eventName = $event['event'];
                $data = $event['data'];

                echo "event: {$eventName}\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                if ($eventName === 'done') {
                    $fullContent = $data['content'] ?? '';
                    $runId = $data['run_id'] ?? null;
                    $meta = $data['meta'] ?? [];
                }
            }

            // Persist the assistant message after streaming completes
            if ($fullContent !== null && $runId !== null) {
                $messageManager->appendAssistantMessage(
                    $employeeId,
                    $sessionId,
                    $fullContent,
                    $runId,
                    $meta ?? [],
                );
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
