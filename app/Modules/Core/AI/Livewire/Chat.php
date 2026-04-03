<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire;

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\Livewire\Concerns\ResolvesAvailableModels;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\AI\Livewire\Concerns\HandlesAttachments;
use App\Modules\Core\AI\Livewire\Concerns\HandlesBackgroundChat;
use App\Modules\Core\AI\Livewire\Concerns\HandlesStreaming;
use App\Modules\Core\AI\Livewire\Concerns\ManagesChatSessions;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\ChatMarkdownRenderer;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\PageContextHolder;
use App\Modules\Core\AI\Services\PageContextResolver;
use App\Modules\Core\AI\Services\QuickActionRegistry;
use App\Modules\Core\AI\Services\RuntimeResponseFactory;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\Workspace\PromptRenderer;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class Chat extends Component
{
    use HandlesAttachments;
    use HandlesBackgroundChat;
    use HandlesStreaming;
    use ManagesChatSessions;
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

    public string $pageAwareness = 'page';

    /** Current page URL sent by the client (window.location.href). */
    public string $pageUrl = '';

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

        $this->resolvePageContext($this->pageAwareness);

        try {
            $messages = $messageManager->read($this->employeeId, $this->selectedSessionId);

            $result = $this->runAi($hasAttachments, $messages, $content);

            // Timeout on interactive run → offload to background
            if (($result['meta']['error_type'] ?? null) === 'timeout') {
                $this->handleTimeoutWithBackgroundOffer($content);

                return;
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

            $this->dispatchPostRunEvents($result);
        } catch (\Throwable $e) {
            $runId = 'run_'.Str::random(12);

            report($e);

            $error = AiRuntimeError::unexpected($e->getMessage());
            $fallback = app(RuntimeResponseFactory::class)->error(
                $runId,
                'unknown',
                'unknown',
                $error,
            );

            $messageManager->appendAssistantMessage(
                $this->employeeId,
                $this->selectedSessionId,
                $fallback['content'],
                $fallback['run_id'],
                $fallback['meta'],
            );
        } finally {
            $this->isLoading = false;
            $this->dispatch('agent-chat-response-ready');
            $this->dispatch('agent-chat-focus-composer');
        }
    }

    /**
     * Run the AI model and return a result array, using orchestration shortcuts when available.
     *
     * @param  list<mixed>  $messages
     * @return array{content: string, run_id: string, meta: array<string, mixed>}
     */
    private function runAi(bool $hasAttachments, array $messages, string $content): array
    {
        if ($this->employeeId === Employee::LARA_ID && ! $hasAttachments) {
            $orchestration = app(LaraOrchestrationService::class)->dispatchFromMessage($content);
            if ($orchestration !== null) {
                return [
                    'content' => $orchestration['assistant_content'],
                    'run_id' => $orchestration['run_id'],
                    'meta' => $orchestration['meta'],
                ];
            }
        }

        $runtime = app(AgenticRuntime::class);

        $systemPrompt = null;

        if ($this->employeeId === Employee::LARA_ID) {
            $factory = app(LaraPromptFactory::class);
            $package = $factory->buildPackage($content);
            $systemPrompt = app(PromptRenderer::class)->render($package);
        }

        $result = $runtime->run($messages, $this->employeeId, $systemPrompt, $this->selectedModel);

        $actionJs = $this->extractAgentAction($result['content']);
        if ($actionJs !== null) {
            $result['content'] = $actionJs['clean_content'];
            $result['meta']['orchestration'] = [
                'status' => 'browser_action',
                'js' => $actionJs['js'],
            ];
        }

        return $result;
    }

    /**
     * Resolve and store page context for the current request lifecycle.
     *
     * Writes to the request-scoped PageContextHolder so LaraPromptFactory
     * and ActivePageSnapshotTool can read it during this request.
     */
    private function resolvePageContext(?string $consentLevel = null): void
    {
        $resolver = app(PageContextResolver::class);
        $holder = app(PageContextHolder::class);

        if (is_string($consentLevel)) {
            $holder->setConsentLevel($consentLevel);
        }

        if ($holder->getConsentLevel() === 'off') {
            return;
        }

        $context = $resolver->resolveFromUrl($this->pageUrl);

        if ($context !== null) {
            $holder->setContext($context);
        }

        if ($holder->getConsentLevel() === 'full') {
            $snapshot = $resolver->resolveSnapshotFromUrl($this->pageUrl);

            if ($snapshot !== null) {
                $holder->setSnapshot($snapshot);
            }
        }
    }

    /**
     * Dispatch post-run JS/navigation events from the result metadata.
     *
     * @param  array{content: string, run_id: string, meta: array<string, mixed>}  $result
     */
    private function dispatchPostRunEvents(array $result): void
    {
        $navigationUrl = $result['meta']['orchestration']['navigation']['url'] ?? null;
        if (is_string($navigationUrl) && str_starts_with($navigationUrl, '/')) {
            $this->dispatch('agent-chat-execute-js', js: "Livewire.navigate('".$navigationUrl."')");
        }

        $actionJs = $result['meta']['orchestration']['js'] ?? null;
        if (is_string($actionJs) && $actionJs !== '') {
            $this->dispatch('agent-chat-execute-js', js: $actionJs);
        }
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

    public function render(): View
    {
        $agentExists = Employee::query()->whereKey($this->employeeId)->exists();
        $agentActivated = $this->isAgentActivated();

        $sessions = [];
        $messages = [];
        $sessionUsage = null;

        if ($agentActivated) {
            $sessions = app(SessionManager::class)->list($this->employeeId);
        }

        if ($agentActivated && $this->selectedSessionId !== null) {
            $messageManager = app(MessageManager::class);
            $messages = $messageManager->read($this->employeeId, $this->selectedSessionId);
            $sessionUsage = $messageManager->sessionUsage($this->employeeId, $this->selectedSessionId);
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
            'sessionUsage' => $sessionUsage,
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
        if (! $user instanceof User) {
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
}
