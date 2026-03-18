<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Services\LlmClient;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\RuntimeCredentialResolver;
use App\Modules\Core\AI\Services\RuntimeMessageBuilder;
use App\Modules\Core\AI\Services\SessionManager;

/**
 * Handles chat session CRUD, title management, and search.
 */
trait ManagesChatSessions
{
    /**
     * Create a new session and select it.
     */
    public function createSession(): void
    {
        if (! $this->isAgentActivated()) {
            return;
        }

        $session = app(SessionManager::class)->create($this->employeeId);
        $this->selectedSessionId = $session->id;
        $this->lastRunMeta = null;
        $this->selectedModel = null;
        $this->dispatch('agent-chat-focus-composer');
    }

    /**
     * Switch to an existing session.
     */
    public function selectSession(string $sessionId): void
    {
        $this->selectedSessionId = $sessionId;
        $this->lastRunMeta = null;

        $session = app(SessionManager::class)->get($this->employeeId, $sessionId);
        $this->selectedModel = $session?->llm['model_override'] ?? null;

        $this->dispatch('agent-chat-focus-composer');
    }

    /**
     * Persist the model override to the session when the user picks a model.
     *
     * Livewire lifecycle hook — called automatically when $selectedModel
     * is updated via wire:model.live on the model selector.
     */
    public function updatedSelectedModel(): void
    {
        if ($this->selectedSessionId !== null && $this->selectedModel !== null) {
            app(SessionManager::class)->updateModelOverride(
                $this->employeeId,
                $this->selectedSessionId,
                $this->selectedModel,
            );
        }
    }

    /**
     * Delete a session and select the next available one.
     */
    public function deleteSession(string $sessionId): void
    {
        if (! $this->isAgentActivated()) {
            return;
        }

        app(SessionManager::class)->delete($this->employeeId, $sessionId);

        if ($this->selectedSessionId === $sessionId) {
            $sessions = app(SessionManager::class)->list($this->employeeId);
            $this->selectedSessionId = empty($sessions) ? null : $sessions[0]->id;
        }

        $this->lastRunMeta = null;
    }

    /**
     * Start inline-editing a session title.
     */
    public function startEditingTitle(string $sessionId): void
    {
        $session = app(SessionManager::class)->get($this->employeeId, $sessionId);
        $this->editingSessionId = $sessionId;
        $this->editingTitle = $session?->title ?? '';
    }

    /**
     * Save the edited session title and exit inline-editing mode.
     */
    public function saveTitle(): void
    {
        if ($this->editingSessionId === null) {
            return;
        }

        $title = trim($this->editingTitle);

        if ($title !== '') {
            app(SessionManager::class)->updateTitle($this->employeeId, $this->editingSessionId, $title);
        }

        $this->editingSessionId = null;
        $this->editingTitle = '';
    }

    /**
     * Cancel inline-editing without saving.
     */
    public function cancelEditingTitle(): void
    {
        $this->editingSessionId = null;
        $this->editingTitle = '';
    }

    /**
     * Ask the agent to generate a session title from the conversation history.
     */
    public function generateSessionTitle(string $sessionId): void
    {
        if (! $this->isAgentActivated()) {
            return;
        }

        $messages = app(MessageManager::class)->read($this->employeeId, $sessionId);
        if ($messages === []) {
            return;
        }

        $config = app(ConfigResolver::class)->resolvePrimaryWithDefaultFallback($this->employeeId);
        if ($config === null) {
            return;
        }

        $credentials = app(RuntimeCredentialResolver::class)->resolve($config);
        if (isset($credentials['error'])) {
            return;
        }

        $title = $this->requestGeneratedSessionTitle($messages, $config, $credentials);
        if ($title === null) {
            return;
        }

        app(SessionManager::class)->updateTitle($this->employeeId, $sessionId, $title);

        if ($this->editingSessionId === $sessionId) {
            $this->editingTitle = $title;
        }
    }

    /**
     * Auto-search when searchQuery property is updated via live binding.
     */
    public function updatedSearchQuery(): void
    {
        $this->searchSessions();
    }

    /**
     * Search across all sessions for messages matching the query.
     */
    public function searchSessions(): void
    {
        $query = trim($this->searchQuery);

        if ($query === '' || mb_strlen($query) < 2) {
            $this->searchResults = [];

            return;
        }

        $results = app(MessageManager::class)->searchSessions($this->employeeId, $query);

        $this->searchResults = array_map(fn (array $r): array => [
            'session_id' => $r['session_id'],
            'title' => $r['title'],
            'snippet' => $r['snippet'],
        ], $results);
    }

    /**
     * Clear search query and results, return to session list.
     */
    public function clearSearch(): void
    {
        $this->searchQuery = '';
        $this->searchResults = [];
    }

    /**
     * Request a concise session title from the configured LLM.
     *
     * @param  array<int, mixed>  $messages
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     */
    private function requestGeneratedSessionTitle(array $messages, array $config, array $credentials): ?string
    {
        $apiMessages = app(RuntimeMessageBuilder::class)->build(
            $messages,
            'Generate a concise 3–6 word title summarizing this conversation. Reply with only the title, no quotes or punctuation.',
        );

        $response = app(LlmClient::class)->chat(new ChatRequest(
            $credentials['base_url'],
            $credentials['api_key'],
            $config['model'],
            $apiMessages,
            maxTokens: 20,
            temperature: 0.5,
            timeout: 15,
            providerName: $config['provider_name'] ?? null,
        ));

        $title = trim($response['content'] ?? '');

        return $title === '' ? null : trim($title, '"\'');
    }
}
