<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire;

use App\Base\AI\Livewire\Concerns\ResolvesAvailableModels;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Livewire\Concerns\HandlesAttachments;
use App\Modules\Core\AI\Livewire\Concerns\HandlesStreaming;
use App\Modules\Core\AI\Livewire\Concerns\ManagesChatSessions;
use App\Modules\Core\AI\Services\ChatMarkdownRenderer;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\OperationsDispatchService;
use App\Modules\Core\AI\Services\QuickActionRegistry;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\TranscriptFallbackBannerAttemptResolver;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class Chat extends Component
{
    use HandlesAttachments;
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
            $this->syncSelectedSessionState($this->selectedSessionId);
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
        $hasPendingDelegations = false;
        $activeTurnsBySession = [];

        if ($agentActivated) {
            $sessions = app(SessionManager::class)->list($this->employeeId);
            $activeTurnsBySession = $this->activeTurnsBySessionForCurrentUser();
        }

        $sessionFallbackBannerAttempt = null;

        if ($agentActivated && $this->selectedSessionId !== null) {
            $messageManager = app(MessageManager::class);
            $messages = $messageManager->read($this->employeeId, $this->selectedSessionId);
            $sessionUsage = $messageManager->sessionUsage($this->employeeId, $this->selectedSessionId);

            $sessionFallbackBannerAttempt = TranscriptFallbackBannerAttemptResolver::latestFailureAttempt($messages);

            if ($this->employeeId === Employee::LARA_ID && is_int(auth()->id())) {
                $hasPendingDelegations = app(OperationsDispatchService::class)
                    ->hasPendingAgentTaskForSession(auth()->id(), $this->selectedSessionId);
            }
        }

        $showSessionFallbackBanner = $sessionFallbackBannerAttempt !== null
            && $this->shouldShowSessionFallbackBanner($sessionFallbackBannerAttempt);

        $markdown = app(ChatMarkdownRenderer::class);

        $canAttach = $this->canAttachFiles();

        $quickActions = ($agentActivated && $messages === [])
            ? app(QuickActionRegistry::class)->forRoute(request()->route()?->getName())
            : [];

        $settingsUrl = $this->settingsUrl();
        $selectedSessionActiveTurn = $this->selectedSessionId !== null
            ? ($activeTurnsBySession[$this->selectedSessionId] ?? null)
            : null;
        $activeTurnCount = count($activeTurnsBySession);

        $messagesWithUi = array_map(function (Message $message): Message {
            $attachments = $message->getMetaArray('attachments', []);
            if ($attachments === []) {
                return new Message(
                    role: $message->role,
                    content: $message->content,
                    timestamp: $message->timestamp,
                    runId: $message->runId,
                    meta: array_merge($message->meta, ['attachments_ui' => []]),
                    type: $message->type,
                );
            }

            $mapped = array_map(function ($att): ?array {
                if (! is_array($att)) {
                    return null;
                }

                $id = $att['id'] ?? null;
                if (! is_string($id) || $id === '') {
                    return null;
                }

                $kind = is_string($att['kind'] ?? null) ? (string) $att['kind'] : 'document';
                $mime = is_string($att['mime_type'] ?? null) ? (string) $att['mime_type'] : 'application/octet-stream';
                $name = is_string($att['original_name'] ?? null) ? (string) $att['original_name'] : $id;
                $size = is_int($att['size'] ?? null) ? (int) $att['size'] : null;

                return [
                    'id' => $id,
                    'kind' => $kind,
                    'mime_type' => $mime,
                    'original_name' => $name,
                    'size' => $size,
                    'url' => route('ai.chat.attachments.show', [
                        'employeeId' => $this->employeeId,
                        'sessionId' => (string) $this->selectedSessionId,
                        'attachmentId' => $id,
                    ]).'?mime='.urlencode($mime),
                ];
            }, $attachments);

            return new Message(
                role: $message->role,
                content: $message->content,
                timestamp: $message->timestamp,
                runId: $message->runId,
                meta: array_merge($message->meta, ['attachments_ui' => array_values(array_filter($mapped))]),
                type: $message->type,
            );
        }, $messages);

        $phaseLabels = array_merge(
            array_reduce(
                TurnPhase::cases(),
                function (array $labels, TurnPhase $phase): array {
                    $labels[$phase->value] = __($phase->label());

                    return $labels;
                },
                [],
            ),
            // Not a phase; represents TurnStatus::Booting.
            ['booting' => __('Starting…')],
        );

        return view('livewire.ai.chat', [
            'agentExists' => $agentExists,
            'agentActivated' => $agentActivated,
            'agentIdentity' => $this->agentIdentity(),
            'sessions' => $sessions,
            'messages' => $messagesWithUi,
            'settingsUrl' => $settingsUrl,
            'canSelectModel' => $this->canSelectModel(),
            'canAttachFiles' => $canAttach,
            'availableModels' => $this->canSelectModel() ? $this->availableModels() : [],
            'currentModel' => $this->resolveCurrentModelLabel(),
            'sessionUsage' => $sessionUsage,
            'hasPendingDelegations' => $hasPendingDelegations,
            'markdown' => $markdown,
            'quickActions' => $quickActions,
            'activeTurnsBySession' => $activeTurnsBySession,
            'selectedSessionActiveTurn' => $selectedSessionActiveTurn,
            'activeTurnCount' => $activeTurnCount,
            'hasActiveTurns' => $activeTurnCount > 0,
            'showSessionFallbackBanner' => $showSessionFallbackBanner,
            'sessionFallbackBannerAttempt' => $sessionFallbackBannerAttempt,
            'phaseLabels' => $phaseLabels,
        ]);
    }

    /**
     * Whether the sticky session fallback notice should appear for the current model selection.
     *
     * The failed attempt is taken from the latest assistant transcript line only; when the user has
     * switched the composer to a different provider/model than that failure, the notice is hidden.
     *
     * @param  array<string, mixed>  $failedAttempt
     */
    public function shouldShowSessionFallbackBanner(array $failedAttempt): bool
    {
        if ($this->selectedModel === null || $this->selectedModel === '') {
            return true;
        }

        $resolved = $this->resolveModelConfigFromComposite($this->selectedModel);

        if (isset($resolved['error'])) {
            return true;
        }

        $failedProvider = is_string($failedAttempt['provider'] ?? null) ? $failedAttempt['provider'] : '';
        $failedModel = is_string($failedAttempt['model'] ?? null) ? $failedAttempt['model'] : '';

        return ($resolved['provider_name'] ?? '') === $failedProvider
            && ($resolved['model'] ?? '') === $failedModel;
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
}
