<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire;

use App\Base\AI\Livewire\Concerns\ResolvesAvailableModels;
use App\Base\AI\Services\LlmClient;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\ChatMarkdownRenderer;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\QuickActionRegistry;
use App\Modules\Core\AI\Services\RuntimeCredentialResolver;
use App\Modules\Core\AI\Services\RuntimeMessageBuilder;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Chat extends Component
{
    use ResolvesAvailableModels;
    use WithFileUploads;

    /**
     * Allowed MIME types for chat attachments.
     */
    private const ATTACHMENT_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'text/plain', 'text/csv', 'text/markdown',
        'application/pdf',
        'application/json',
    ];

    /**
     * Maximum attachment file size in bytes (10 MB).
     */
    private const ATTACHMENT_MAX_SIZE = 10 * 1024 * 1024;

    public int $employeeId = Employee::LARA_ID;

    public string $messageInput = '';

    public ?string $selectedSessionId = null;

    public bool $isLoading = false;

    /** @var array<string, mixed>|null */
    public ?array $lastRunMeta = null;

    public ?string $selectedModel = null;

    public ?string $editingSessionId = null;

    public string $editingTitle = '';

    /** @var list<TemporaryUploadedFile> */
    public array $attachments = [];

    public string $searchQuery = '';

    /** @var list<array{session_id: string, title: string|null, snippet: string}> */
    public array $searchResults = [];

    public function mount(int $employeeId = Employee::LARA_ID): void
    {
        $this->employeeId = $employeeId;

        if (! $this->isAgentActivated()) {
            return;
        }

        $sessions = app(SessionManager::class)->list($this->employeeId);
        if (! empty($sessions)) {
            $this->selectedSessionId = $sessions[0]->id;
        }
    }

    #[On('agent-chat-opened')]
    public function onAgentChatOpened(): void
    {
        if (! $this->isAgentActivated()) {
            return;
        }

        if ($this->selectedSessionId === null) {
            $sessions = app(SessionManager::class)->list($this->employeeId);
            if (! empty($sessions)) {
                $this->selectedSessionId = $sessions[0]->id;
            }
        }

        $this->dispatch('agent-chat-focus-composer');
    }

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
     * Remove a pending attachment by index before sending.
     */
    public function removeAttachment(int $index): void
    {
        if (isset($this->attachments[$index])) {
            unset($this->attachments[$index]);
            $this->attachments = array_values($this->attachments);
        }
    }

    /**
     * Check if the current user has attachment upload capability.
     */
    public function canAttachFiles(): bool
    {
        $user = auth()->user();
        if (! $user instanceof \App\Modules\Core\User\Models\User) {
            return false;
        }

        $actor = Actor::forUser($user);

        return app(AuthorizationService::class)->can($actor, 'ai.chat_attachments.manage')->allowed;
    }

    public function sendMessage(): void
    {
        $hasAttachments = $this->attachments !== [] && $this->canAttachFiles();
        $hasText = trim($this->messageInput) !== '';

        if (! $this->isAgentActivated() || (! $hasText && ! $hasAttachments)) {
            return;
        }

        $sessionManager = app(SessionManager::class);
        if ($this->selectedSessionId === null) {
            $session = $sessionManager->create($this->employeeId);
            $this->selectedSessionId = $session->id;
        }

        $this->isLoading = true;
        $content = trim($this->messageInput);
        $this->messageInput = '';

        $attachmentMeta = $hasAttachments
            ? $this->processAttachments($this->selectedSessionId)
            : [];
        $this->attachments = [];

        $userMeta = $attachmentMeta !== [] ? ['attachments' => $attachmentMeta] : [];

        $messageManager = app(MessageManager::class);
        $messageManager->appendUserMessage($this->employeeId, $this->selectedSessionId, $content, $userMeta);

        $messages = $messageManager->read($this->employeeId, $this->selectedSessionId);

        // Skip orchestration shortcuts when attachments are present
        $orchestration = null;
        if ($this->employeeId === Employee::LARA_ID && ! $hasAttachments) {
            $orchestration = app(LaraOrchestrationService::class)->dispatchFromMessage($content);
        }

        if ($orchestration !== null) {
            $result = [
                'content' => $orchestration['assistant_content'],
                'run_id' => $orchestration['run_id'],
                'meta' => $orchestration['meta'],
            ];
        } else {
            $runtime = app(AgenticRuntime::class);

            $systemPrompt = $this->employeeId === Employee::LARA_ID
                ? app(LaraPromptFactory::class)->buildForCurrentUser($content)
                : null;

            $result = $runtime->run($messages, $this->employeeId, $systemPrompt, $this->selectedModel);

            $actionJs = $this->extractAgentAction($result['content']);
            if ($actionJs !== null) {
                $result['content'] = $actionJs['clean_content'];
                $result['meta']['orchestration'] = [
                    'status' => 'browser_action',
                    'js' => $actionJs['js'],
                ];
            }
        }

        $messageManager->appendAssistantMessage(
            $this->employeeId,
            $this->selectedSessionId,
            $result['content'],
            $result['run_id'],
            $result['meta'],
        );

        $this->lastRunMeta = [
            'run_id' => $result['run_id'],
            ...$result['meta'],
        ];

        $navigationUrl = $result['meta']['orchestration']['navigation']['url'] ?? null;
        if (is_string($navigationUrl) && str_starts_with($navigationUrl, '/')) {
            $this->dispatch('agent-chat-execute-js', js: "Livewire.navigate('".$navigationUrl."')");
        }

        $actionJs = $result['meta']['orchestration']['js'] ?? null;
        if (is_string($actionJs) && $actionJs !== '') {
            $this->dispatch('agent-chat-execute-js', js: $actionJs);
        }

        $this->isLoading = false;
        $this->dispatch('agent-chat-response-ready');
        $this->dispatch('agent-chat-focus-composer');
    }

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

    /**
     * Get identity display data for the current agent.
     *
     * @return array{name: string, role: string, icon: string, shortcut: string|null}
     */
    public function agentIdentity(): array
    {
        if ($this->employeeId === Employee::LARA_ID) {
            return [
                'name' => 'Lara',
                'role' => __('System Agent'),
                'icon' => 'heroicon-o-sparkles',
                'shortcut' => 'Ctrl+K',
            ];
        }

        $employee = Employee::query()->find($this->employeeId);

        return [
            'name' => $employee?->short_name ?? __('Agent'),
            'role' => $employee?->designation ?? __('Agent'),
            'icon' => 'heroicon-o-cpu-chip',
            'shortcut' => null,
        ];
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $agentExists = Employee::query()->whereKey($this->employeeId)->exists();
        $agentActivated = $this->isAgentActivated();

        $sessions = [];
        $messages = [];

        if ($agentActivated) {
            $sessions = app(SessionManager::class)->list($this->employeeId);
        }

        if ($agentActivated && $this->selectedSessionId !== null) {
            $messages = app(MessageManager::class)->read($this->employeeId, $this->selectedSessionId);
        }

        $markdown = app(ChatMarkdownRenderer::class);

        $canAttach = $this->canAttachFiles();

        $quickActions = ($agentActivated && $messages === [])
            ? app(QuickActionRegistry::class)->forRoute(request()->route()?->getName())
            : [];

        $settingsUrl = $this->settingsUrl();

        return view('livewire.ai.chat', [
            'agentExists' => $agentExists,
            'agentActivated' => $agentActivated,
            'agentIdentity' => $this->agentIdentity(),
            'sessions' => $sessions,
            'messages' => $messages,
            'settingsUrl' => $settingsUrl,
            'canSelectModel' => $this->canSelectModel(),
            'canAttachFiles' => $canAttach,
            'availableModels' => $this->canSelectModel() ? $this->availableModels() : [],
            'currentModel' => $this->resolveCurrentModelLabel(),
            'markdown' => $markdown,
            'quickActions' => $quickActions,
        ]);
    }

    /**
     * Check if the current user has model selection capability.
     */
    public function canSelectModel(): bool
    {
        $user = auth()->user();
        if (! $user instanceof \App\Modules\Core\User\Models\User) {
            return false;
        }

        $actor = Actor::forUser($user);

        return app(AuthorizationService::class)->can($actor, 'ai.chat_model.manage')->allowed;
    }

    /**
     * Get available models for the model picker dropdown.
     *
     * Delegates to the shared ResolvesAvailableModels concern, returning
     * composite "providerId:::modelId" identifiers for correct cross-provider
     * model overrides.
     *
     * @return list<array{id: string, label: string, provider: string, providerId: int}>
     */
    public function availableModels(): array
    {
        $employee = Employee::query()->find($this->employeeId);
        $companyId = $employee?->company_id ? (int) $employee->company_id : null;

        if ($companyId === null) {
            return [];
        }

        return $this->loadAvailableModels($companyId);
    }

    /**
     * Get the display label for the currently active model.
     *
     * Extracts the model_id from a composite "providerId:::modelId" string
     * when a model override is set.
     */
    private function resolveCurrentModelLabel(): string
    {
        if ($this->selectedModel !== null) {
            return $this->extractModelId($this->selectedModel) ?? $this->selectedModel;
        }

        $config = app(ConfigResolver::class)->resolvePrimaryWithDefaultFallback($this->employeeId);

        return $config['model'] ?? __('Default');
    }

    private function isAgentActivated(): bool
    {
        $isActivated = false;

        if (Employee::query()->whereKey($this->employeeId)->exists()) {
            $resolver = app(ConfigResolver::class);
            $configs = $resolver->resolve($this->employeeId);
            $isActivated = count($configs) > 0;

            if (! $isActivated) {
                $employee = Employee::query()->find($this->employeeId);
                $companyId = $employee?->company_id ? (int) $employee->company_id : null;

                if ($companyId !== null) {
                    $isActivated = $resolver->resolveDefault($companyId) !== null;
                }
            }
        }

        return $isActivated;
    }

    private function settingsUrl(): ?string
    {
        if ($this->employeeId !== Employee::LARA_ID) {
            return null;
        }

        return route('admin.setup.lara');
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

        $response = app(LlmClient::class)->chat(new \App\Base\AI\DTO\ChatRequest(
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

    /**
     * Extract `<agent-action>` JS block from LLM response content.
     *
     * @return array{js: string, clean_content: string}|null
     */
    private function extractAgentAction(string $content): ?array
    {
        if (preg_match('/<agent-action>(.*?)<\/agent-action>/s', $content, $matches) !== 1) {
            return null;
        }

        $js = trim($matches[1]);
        $clean = trim(str_replace($matches[0], '', $content));

        if ($js === '') {
            return null;
        }

        return ['js' => $js, 'clean_content' => $clean ?: $js];
    }

    /**
     * Process pending attachments: validate, store to session workspace, extract text for documents.
     *
     * @return list<array{id: string, original_name: string, stored_path: string, mime_type: string, size: int, kind: string, extracted_text_path: string|null}>
     */
    private function processAttachments(string $sessionId): array
    {
        $sessionManager = app(SessionManager::class);
        $basePath = $sessionManager->sessionsPath($this->employeeId);
        $attachDir = $basePath.'/attachments/'.$sessionId;

        if (! is_dir($attachDir)) {
            mkdir($attachDir, 0755, true);
        }

        $processed = [];

        foreach ($this->attachments as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $mime = $file->getMimeType() ?? '';
            $size = $file->getSize() ?? 0;

            if (! in_array($mime, self::ATTACHMENT_MIMES, true) || $size > self::ATTACHMENT_MAX_SIZE) {
                continue;
            }

            $id = 'att_'.Str::random(12);
            $originalName = $file->getClientOriginalName();
            $storedPath = $attachDir.'/'.$id.'_'.$originalName;

            $file->storeAs(
                path: '',
                name: $id.'_'.$originalName,
                options: ['disk' => 'local', 'path' => $attachDir],
            );

            // Livewire storeAs uses the configured disk; copy to workspace directly
            copy($file->getRealPath(), $storedPath);

            $isImage = str_starts_with($mime, 'image/');
            $extractedTextPath = null;

            if (! $isImage) {
                $extractedTextPath = $this->extractTextFromFile($storedPath, $mime, $attachDir, $id);
            }

            $processed[] = [
                'id' => $id,
                'original_name' => $originalName,
                'stored_path' => $storedPath,
                'mime_type' => $mime,
                'size' => $size,
                'kind' => $isImage ? 'image' : 'document',
                'extracted_text_path' => $extractedTextPath,
            ];
        }

        return $processed;
    }

    /**
     * Extract readable text from a document file and write a sidecar .txt file.
     */
    private function extractTextFromFile(string $filePath, string $mimeType, string $attachDir, string $id): ?string
    {
        $text = null;

        if (in_array($mimeType, ['text/plain', 'text/csv', 'text/markdown', 'application/json'], true)) {
            $text = file_get_contents($filePath);
        } elseif ($mimeType === 'application/pdf') {
            $text = $this->extractPdfText($filePath);
        }

        if ($text === null || $text === false || trim($text) === '') {
            return null;
        }

        $sidecarPath = $attachDir.'/'.$id.'.extracted.txt';
        file_put_contents($sidecarPath, $text);

        return $sidecarPath;
    }

    /**
     * Extract text from a PDF file using pdftotext if available.
     */
    private function extractPdfText(string $filePath): ?string
    {
        $binary = trim((string) shell_exec('which pdftotext 2>/dev/null'));

        if ($binary === '') {
            return null;
        }

        $escaped = escapeshellarg($filePath);
        $output = shell_exec("{$binary} {$escaped} - 2>/dev/null");

        return is_string($output) && trim($output) !== '' ? $output : null;
    }
}
