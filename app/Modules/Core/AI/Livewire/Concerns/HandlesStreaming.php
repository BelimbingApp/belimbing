<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Employee\Models\Employee;

/**
 * Handles streaming chat run preparation and finalization.
 */
trait HandlesStreaming
{
    /**
     * Prepare a streaming run: persist user message, return SSE URL.
     *
     * The client opens an EventSource to the returned URL. The SSE endpoint
     * streams the response and persists the assistant message on completion.
     * Falls back to synchronous sendMessage() if streaming is unavailable.
     *
     * @return array{url: string, session_id: string}|null Null signals fallback to sync
     */
    public function prepareStreamingRun(): ?array
    {
        $hasAttachments = $this->attachments !== [] && $this->canAttachFiles();
        $hasText = trim($this->messageInput) !== '';

        if (! $this->isAgentActivated() || (! $hasText && ! $hasAttachments)) {
            return null;
        }

        $sessionManager = app(SessionManager::class);
        if ($this->selectedSessionId === null) {
            $session = $sessionManager->create($this->employeeId);
            $this->selectedSessionId = $session->id;
        }

        $content = trim($this->messageInput);
        $this->messageInput = '';

        $attachmentMeta = $hasAttachments
            ? $this->processAttachments($this->selectedSessionId)
            : [];
        $this->attachments = [];

        $userMeta = $attachmentMeta !== [] ? ['attachments' => $attachmentMeta] : [];

        // Check for orchestration shortcuts (sync-only, no streaming)
        if ($this->employeeId === Employee::LARA_ID && ! $hasAttachments) {
            $orchestration = app(LaraOrchestrationService::class)->dispatchFromMessage($content);
            if ($orchestration !== null) {
                // Fall through to sync path — return null to signal caller
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

                return null;
            }
        }

        $messageManager = app(MessageManager::class);
        $messageManager->appendUserMessage($this->employeeId, $this->selectedSessionId, $content, $userMeta);

        $url = route('ai.chat.stream', array_filter([
            'employee_id' => $this->employeeId,
            'session_id' => $this->selectedSessionId,
            'model' => $this->selectedModel,
        ]));

        return [
            'url' => $url,
            'session_id' => $this->selectedSessionId,
        ];
    }

    /**
     * Finalize a completed streaming run by refreshing component state.
     */
    public function finalizeStreamingRun(): void
    {
        $this->isLoading = false;
        $this->dispatch('agent-chat-response-ready');
        $this->dispatch('agent-chat-focus-composer');
    }
}
