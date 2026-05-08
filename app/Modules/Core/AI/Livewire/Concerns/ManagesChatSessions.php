<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\Runtime\SimpleTaskExecutor;
use App\Modules\Core\User\Models\User;

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
        $this->selectedModel = $this->seedModelFromUserPrefs();
        $this->dispatch('agent-chat-focus-composer');
    }

    /**
     * Build the composite model id from the current user's last-used hint for this agent.
     */
    private function seedModelFromUserPrefs(): ?string
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $hint = $user->getLastUsedModel($this->employeeId);

        if ($hint === null) {
            return null;
        }

        $companyId = $user->getCompanyId();

        if ($companyId === null) {
            return null;
        }

        $provider = AiProvider::query()
            ->forCompany($companyId)
            ->active()
            ->where('name', $hint['provider'])
            ->first();

        if ($provider === null) {
            return null;
        }

        return $provider->id.self::MODEL_ID_SEPARATOR.$hint['model'];
    }

    /**
     * Persist the user's last-used model hint based on the picker's composite id.
     */
    private function persistUserLastUsedModel(?string $compositeId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        if ($compositeId === null) {
            $user->setLastUsedModel($this->employeeId, null, null);

            return;
        }

        $resolved = $this->resolveModelConfigFromComposite($compositeId);

        if (isset($resolved['error'])) {
            return;
        }

        $user->setLastUsedModel(
            $this->employeeId,
            $resolved['provider_name'] ?? null,
            $resolved['model'] ?? null,
        );
    }

    /**
     * Hydrate session-scoped UI state when the selected session changes from the client.
     *
     * This covers localStorage restoration and direct client-side property updates,
     * which bypass the explicit selectSession() action.
     */
    public function updatedSelectedSessionId(): void
    {
        $this->lastRunMeta = null;
        $this->syncSelectedSessionState($this->selectedSessionId, dispatchSelectionEvent: true);
    }

    /**
     * Switch to an existing session.
     */
    public function selectSession(string $sessionId): void
    {
        $this->selectedSessionId = $sessionId;
        $this->lastRunMeta = null;
        $this->syncSelectedSessionState($sessionId, dispatchSelectionEvent: true);
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
        $this->selectedModel = $this->normalizeModelOverride($this->selectedModel);

        if ($this->selectedSessionId !== null) {
            app(SessionManager::class)->updateModelOverride(
                $this->employeeId,
                $this->selectedSessionId,
                $this->selectedModel,
            );
        }

        $this->persistUserLastUsedModel($this->selectedModel);
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
            $this->syncSelectedSessionState($this->selectedSessionId, dispatchSelectionEvent: true);
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

        $title = app(SimpleTaskExecutor::class)->execute(
            employeeId: $this->employeeId,
            taskKey: 'titling',
            messages: $messages,
            systemPrompt: 'Generate a concise 3–6 word title summarizing this conversation. Reply with only the title, no quotes or punctuation.',
            maxOutputTokens: 20,
            timeout: 15,
            sessionId: $sessionId,
        );

        if ($title === null) {
            return;
        }

        $title = trim($title, '"\'');
        if ($title === '') {
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

    private function syncSelectedSessionState(?string $sessionId, bool $dispatchSelectionEvent = false): void
    {
        if (! is_string($sessionId) || $sessionId === '') {
            $this->selectedModel = null;

            return;
        }

        $session = app(SessionManager::class)->get($this->employeeId, $sessionId);
        $this->selectedModel = $this->normalizeModelOverride($session?->llm['model_override'] ?? null);

        if (! $dispatchSelectionEvent) {
            return;
        }

        $activeTurn = $this->findActiveTurnForSession($sessionId);
        $this->dispatch(
            'agent-chat-session-selected',
            sessionId: $sessionId,
            activeTurnId: $activeTurn?->id,
            activeRunPhase: $activeTurn?->current_phase?->value,
            activeTurnLabel: $activeTurn?->current_label ?? $activeTurn?->current_phase?->label(),
            activeRunStartedAt: $activeTurn?->started_at?->toIso8601String(),
            activeTurnCreatedAt: $activeTurn?->created_at?->toIso8601String(),
        );
    }

    private function normalizeModelOverride(mixed $modelOverride): ?string
    {
        return is_string($modelOverride) && $modelOverride !== ''
            ? $modelOverride
            : null;
    }
}
