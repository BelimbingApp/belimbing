<?php

namespace App\Modules\Core\AI\Livewire;

use App\Base\AI\Livewire\Concerns\ResolvesAvailableModels;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\DTO\Session;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Livewire\Concerns\HandlesAttachments;
use App\Modules\Core\AI\Livewire\Concerns\HandlesStreaming;
use App\Modules\Core\AI\Livewire\Concerns\ManagesChatSessions;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\ChatMarkdownRenderer;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\OperationsDispatchService;
use App\Modules\Core\AI\Services\QuickActionRegistry;
use App\Modules\Core\AI\Services\SessionManager;
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

    public ?string $titleSuggestionSessionId = null;

    public ?string $titleSuggestionMessage = null;

    public string $titleSuggestionTone = 'info';

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

        $state = $this->resolveRenderState($agentActivated);
        $sessions = $state['sessions'];
        $messages = $state['messages'];
        $sessionUsage = $state['sessionUsage'];
        $hasPendingDelegations = $state['hasPendingDelegations'];
        $activeTurnsBySession = $state['activeTurnsBySession'];
        $canAccessControlPlane = $this->canAccessControlPlane();

        $availableModels = $this->canSelectModel() ? $this->availableModels() : [];
        $this->ensureSelectedModelPopulated($availableModels);
        $sessionTurnTargets = $agentActivated
            ? $this->sessionTurnTargets($sessions, $activeTurnsBySession)
            : [];
        $selectedSessionTurnTarget = $this->selectedSessionId !== null
            ? ($sessionTurnTargets[$this->selectedSessionId] ?? null)
            : null;

        $markdown = app(ChatMarkdownRenderer::class);

        $canAttach = $this->canAttachFiles();

        $quickActions = $this->quickActions($agentActivated, $messages);

        $settingsUrl = $this->settingsUrl();
        $selectedSessionActiveTurn = $this->selectedSessionId !== null
            ? ($activeTurnsBySession[$this->selectedSessionId] ?? null)
            : null;
        $activeTurnCount = count($activeTurnsBySession);

        $messagesWithUi = $this->mapMessagesWithUi($messages);

        $phaseLabels = $this->phaseLabels();

        return view('livewire.ai.chat', [
            'agentExists' => $agentExists,
            'agentActivated' => $agentActivated,
            'agentIdentity' => $this->agentIdentity(),
            'sessions' => $sessions,
            'messages' => $messagesWithUi,
            'settingsUrl' => $settingsUrl,
            'canSelectModel' => $this->canSelectModel(),
            'canAttachFiles' => $canAttach,
            'availableModels' => $availableModels,
            'currentModel' => $this->resolveCurrentModelLabel(),
            'sessionUsage' => $sessionUsage,
            'hasPendingDelegations' => $hasPendingDelegations,
            'markdown' => $markdown,
            'quickActions' => $quickActions,
            'activeTurnsBySession' => $activeTurnsBySession,
            'selectedSessionActiveTurn' => $selectedSessionActiveTurn,
            'activeTurnCount' => $activeTurnCount,
            'hasActiveTurns' => $activeTurnCount > 0,
            'phaseLabels' => $phaseLabels,
            'canAccessControlPlane' => $canAccessControlPlane,
            'sessionTurnTargets' => $sessionTurnTargets,
            'selectedSessionTurnTarget' => $selectedSessionTurnTarget,
        ]);
    }

    /**
     * @return array{
     *     sessions: list<Session>,
     *     messages: list<Message>,
     *     sessionUsage: mixed,
     *     hasPendingDelegations: bool,
     *     activeTurnsBySession: array<string, array{runId: string, session_id: string, replayUrl: string, phase: string|null, label: string|null, started_at: string|null, created_at: string|null, timer_anchor_at: string|null, cancel_requested_at: string|null, status: string}>
     * }
     */
    private function resolveRenderState(bool $agentActivated): array
    {
        $sessions = [];
        $messages = [];
        $sessionUsage = null;
        $hasPendingDelegations = false;
        $activeTurnsBySession = [];

        if (! $agentActivated) {
            return compact(
                'sessions',
                'messages',
                'sessionUsage',
                'hasPendingDelegations',
                'activeTurnsBySession',
            );
        }

        $sessions = app(SessionManager::class)->list($this->employeeId);
        $activeTurnsBySession = $this->activeTurnsBySessionForCurrentUser();

        if ($this->selectedSessionId === null) {
            return compact(
                'sessions',
                'messages',
                'sessionUsage',
                'hasPendingDelegations',
                'activeTurnsBySession',
            );
        }

        $messageManager = app(MessageManager::class);
        $messages = $messageManager->read($this->employeeId, $this->selectedSessionId);
        $sessionUsage = $messageManager->sessionUsage($this->employeeId, $this->selectedSessionId);

        if ($this->employeeId === Employee::LARA_ID && is_int(auth()->id())) {
            $hasPendingDelegations = app(OperationsDispatchService::class)
                ->hasPendingAgentTaskForSession(auth()->id(), $this->selectedSessionId);
        }

        return compact(
            'sessions',
            'messages',
            'sessionUsage',
            'hasPendingDelegations',
            'activeTurnsBySession',
        );
    }

    /**
     * @param  list<Message>  $messages
     * @return list<Message>
     */
    private function mapMessagesWithUi(array $messages): array
    {
        return array_map(function (Message $message): Message {
            $attachments = $message->getMetaArray('attachments', []);
            if ($attachments === []) {
                return $this->messageWithAttachmentsUi($message, []);
            }

            $mapped = array_map(fn ($att): ?array => $this->attachmentToUi($att), $attachments);

            return $this->messageWithAttachmentsUi($message, array_values(array_filter($mapped)));
        }, $messages);
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function attachmentUiUrl(array $attachment): string
    {
        $mime = is_string($attachment['mime_type'] ?? null) ? (string) $attachment['mime_type'] : 'application/octet-stream';
        $id = is_string($attachment['id'] ?? null) ? (string) $attachment['id'] : '';

        return route('ai.chat.attachments.show', [
            'employeeId' => $this->employeeId,
            'sessionId' => (string) $this->selectedSessionId,
            'attachmentId' => $id,
        ]).'?mime='.urlencode($mime);
    }

    /**
     * @return array{id: string, kind: string, mime_type: string, original_name: string, size: int|null, url: string}|null
     */
    private function attachmentToUi(mixed $attachment): ?array
    {
        if (! is_array($attachment)) {
            return null;
        }

        $id = $attachment['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        $kind = is_string($attachment['kind'] ?? null) ? (string) $attachment['kind'] : 'document';
        $mime = is_string($attachment['mime_type'] ?? null) ? (string) $attachment['mime_type'] : 'application/octet-stream';
        $name = is_string($attachment['original_name'] ?? null) ? (string) $attachment['original_name'] : $id;
        $size = is_int($attachment['size'] ?? null) ? (int) $attachment['size'] : null;

        return [
            'id' => $id,
            'kind' => $kind,
            'mime_type' => $mime,
            'original_name' => $name,
            'size' => $size,
            'url' => $this->attachmentUiUrl(['id' => $id, 'mime_type' => $mime]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $attachmentsUi
     */
    private function messageWithAttachmentsUi(Message $message, array $attachmentsUi): Message
    {
        return new Message(
            role: $message->role,
            content: $message->content,
            timestamp: $message->timestamp,
            runId: $message->runId,
            meta: array_merge($message->meta, ['attachments_ui' => $attachmentsUi]),
            type: $message->type,
        );
    }

    /**
     * @param  list<Message>  $messages
     * @return list<array<string, mixed>>
     */
    private function quickActions(bool $agentActivated, array $messages): array
    {
        if (! $agentActivated || $messages !== []) {
            return [];
        }

        return app(QuickActionRegistry::class)->forRoute(request()->route()?->getName());
    }

    /**
     * @return array<string, string>
     */
    private function phaseLabels(): array
    {
        $labels = [];

        foreach (RunPhase::cases() as $phase) {
            $labels[$phase->value] = __($phase->label());
        }

        // Not a phase; represents AiRunStatus::Booting.
        $labels['booting'] = __('Starting…');

        return $labels;
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

        return app(AuthorizationService::class)->can($actor, 'admin.ai.chat-model.manage')->allowed;
    }

    public function canAccessControlPlane(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return app(AuthorizationService::class)
            ->can(Actor::forUser($user), 'admin.ai.control-plane.view')
            ->allowed;
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
     * Ensure $selectedModel reflects a real composite ID at render time so the
     * model picker shows the active model selected within its provider group
     * instead of an orphan placeholder option above the groups.
     *
     * @param  list<array{id: string, label: string, provider: string, providerId: int}>  $availableModels
     */
    private function ensureSelectedModelPopulated(array $availableModels): void
    {
        if ($this->selectedModel !== null || $availableModels === []) {
            return;
        }

        $employee = Employee::query()->find($this->employeeId);
        $companyId = $employee?->company_id ? (int) $employee->company_id : null;

        if ($companyId === null) {
            return;
        }

        $default = $this->resolveDefaultCompositeModelId($companyId);

        if ($default !== '') {
            $this->selectedModel = $default;
        }
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

        $config = app(ConfigResolver::class)->resolveDefault($this->employeeId);

        return $config['model'] ?? __('Default');
    }

    private function isAgentActivated(): bool
    {
        if (! Employee::query()->whereKey($this->employeeId)->exists()) {
            return false;
        }

        return app(ConfigResolver::class)->resolveDefault($this->employeeId) !== null;
    }

    private function settingsUrl(): ?string
    {
        if ($this->employeeId !== Employee::LARA_ID) {
            return null;
        }

        return route('admin.setup.lara');
    }

    /**
     * @param  list<Session>  $sessions
     * @param  array<string, array{runId: string, session_id: string, replayUrl: string, phase: string|null, label: string|null, started_at: string|null, created_at: string|null, timer_anchor_at: string|null, cancel_requested_at: string|null, status: string}>  $activeTurnsBySession
     * @return array<string, array{run_id: string, is_active: bool}>
     */
    private function sessionTurnTargets(array $sessions, array $activeTurnsBySession): array
    {
        $userId = auth()->id();

        if (! is_numeric($userId) || $sessions === []) {
            return [];
        }

        $sessionIds = array_map(
            static fn ($session): string => $session->id,
            $sessions,
        );

        $latestTurns = AiRun::query()
            ->where('employee_id', $this->employeeId)
            ->where('acting_for_user_id', (int) $userId)
            ->where('source', 'chat')
            ->whereIn('session_id', $sessionIds)
            ->orderByDesc('created_at')
            ->get(['id', 'session_id']);

        $targets = [];

        foreach ($latestTurns as $turn) {
            if (isset($targets[$turn->session_id])) {
                continue;
            }

            $activeTurnId = $activeTurnsBySession[$turn->session_id]['runId'] ?? null;

            $targets[$turn->session_id] = [
                'run_id' => $turn->id,
                'is_active' => is_string($activeTurnId) && $activeTurnId === $turn->id,
            ];
        }

        return $targets;
    }
}
