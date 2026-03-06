<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\DigitalWorkerRuntime;
use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    public string $messageInput = '';

    public ?string $selectedSessionId = null;

    public bool $isLoading = false;

    /** @var array<string, mixed>|null */
    public ?array $lastRunMeta = null;

    public function mount(): void
    {
        if (! $this->isLaraActivated()) {
            return;
        }

        $sessions = app(SessionManager::class)->list(Employee::LARA_ID);
        if (count($sessions) > 0) {
            $this->selectedSessionId = $sessions[0]->id;
        }
    }

    #[On('lara-chat-opened')]
    public function onLaraChatOpened(): void
    {
        if (! $this->isLaraActivated()) {
            return;
        }

        if ($this->selectedSessionId === null) {
            $sessions = app(SessionManager::class)->list(Employee::LARA_ID);
            if (count($sessions) > 0) {
                $this->selectedSessionId = $sessions[0]->id;
            }
        }

        $this->dispatch('lara-focus-composer');
    }

    public function createSession(): void
    {
        if (! $this->isLaraActivated()) {
            return;
        }

        $session = app(SessionManager::class)->create(Employee::LARA_ID);
        $this->selectedSessionId = $session->id;
        $this->lastRunMeta = null;
        $this->dispatch('lara-focus-composer');
    }

    public function selectSession(string $sessionId): void
    {
        $this->selectedSessionId = $sessionId;
        $this->lastRunMeta = null;
        $this->dispatch('lara-focus-composer');
    }

    public function deleteSession(string $sessionId): void
    {
        if (! $this->isLaraActivated()) {
            return;
        }

        app(SessionManager::class)->delete(Employee::LARA_ID, $sessionId);

        if ($this->selectedSessionId === $sessionId) {
            $sessions = app(SessionManager::class)->list(Employee::LARA_ID);
            $this->selectedSessionId = count($sessions) > 0 ? $sessions[0]->id : null;
        }

        $this->lastRunMeta = null;
    }

    public function sendMessage(): void
    {
        if (! $this->isLaraActivated() || trim($this->messageInput) === '') {
            return;
        }

        $sessionManager = app(SessionManager::class);
        if ($this->selectedSessionId === null) {
            $session = $sessionManager->create(Employee::LARA_ID);
            $this->selectedSessionId = $session->id;
        }

        $this->isLoading = true;
        $content = trim($this->messageInput);
        $this->messageInput = '';

        $messageManager = app(MessageManager::class);
        $messageManager->appendUserMessage(Employee::LARA_ID, $this->selectedSessionId, $content);

        $messages = $messageManager->read(Employee::LARA_ID, $this->selectedSessionId);
        $orchestration = app(LaraOrchestrationService::class)->dispatchFromMessage($content);

        if ($orchestration !== null) {
            $result = [
                'content' => $orchestration['assistant_content'],
                'run_id' => $orchestration['run_id'],
                'meta' => $orchestration['meta'],
            ];
        } else {
            $runtime = app(DigitalWorkerRuntime::class);
            $systemPrompt = app(LaraPromptFactory::class)->buildForCurrentUser();
            $result = $runtime->run($messages, Employee::LARA_ID, $systemPrompt);
        }

        $messageManager->appendAssistantMessage(
            Employee::LARA_ID,
            $this->selectedSessionId,
            $result['content'],
            $result['run_id'],
            $result['meta'],
        );

        $this->lastRunMeta = [
            'run_id' => $result['run_id'],
            ...$result['meta'],
        ];

        $session = $sessionManager->get(Employee::LARA_ID, $this->selectedSessionId);
        if ($session && $session->title === null) {
            $title = mb_substr($content, 0, 60);

            if (mb_strlen($content) > 60) {
                $title .= '…';
            }

            $sessionManager->updateTitle(Employee::LARA_ID, $this->selectedSessionId, $title);
        }

        $this->isLoading = false;
        $this->dispatch('lara-focus-composer');
    }

    /**
     * @return array<string, mixed>
     */
    public function with(): array
    {
        $laraExists = Employee::query()->whereKey(Employee::LARA_ID)->exists();
        $laraActivated = $this->isLaraActivated();

        $sessions = [];
        $messages = [];

        if ($laraActivated) {
            $sessions = app(SessionManager::class)->list(Employee::LARA_ID);
        }

        if ($laraActivated && $this->selectedSessionId !== null) {
            $messages = app(MessageManager::class)->read(Employee::LARA_ID, $this->selectedSessionId);
        }

        return [
            'laraExists' => $laraExists,
            'laraActivated' => $laraActivated,
            'sessions' => $sessions,
            'messages' => $messages,
        ];
    }

    private function isLaraActivated(): bool
    {
        if (! Employee::query()->whereKey(Employee::LARA_ID)->exists()) {
            return false;
        }

        $resolver = app(ConfigResolver::class);
        $configs = $resolver->resolve(Employee::LARA_ID);
        if (count($configs) > 0) {
            return true;
        }

        if (! Company::query()->whereKey(Company::LICENSEE_ID)->exists()) {
            return false;
        }

        return $resolver->resolveDefault(Company::LICENSEE_ID) !== null;
    }
}; ?>

<div class="h-full flex flex-col" x-data @lara-focus-composer.window="$nextTick(() => $refs.laraComposer?.focus())">
    <div class="h-11 px-4 border-b border-border-default bg-surface-bar flex items-center justify-between shrink-0">
        <div class="flex items-center gap-2">
            <x-ai.lara-identity :status="$laraActivated ? 'online' : null" />
        </div>

        <button
            type="button"
            x-on:click="$dispatch('close-lara-chat')"
            class="text-muted hover:text-ink transition-colors"
            title="{{ __('Close Lara chat') }}"
            aria-label="{{ __('Close Lara chat') }}"
        >
            <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
        </button>
    </div>

    @if (! $laraExists)
        <div class="p-4">
            <x-ui.alert variant="warning">
                {{ __('Lara has not been provisioned yet.') }}
                <a href="{{ route('admin.setup.lara') }}" wire:navigate class="text-accent hover:underline">
                    {{ __('Set up Lara') }}
                </a>
            </x-ui.alert>
        </div>
    @elseif (! $laraActivated)
        <div class="p-4">
            <x-ui.alert variant="info">
                {{ __('Lara is not activated yet. Configure an AI provider to start chatting.') }}
                <a href="{{ route('admin.setup.lara') }}" wire:navigate class="text-accent hover:underline">
                    {{ __('Activate Lara') }}
                </a>
            </x-ui.alert>
        </div>
    @else
        <div class="flex-1 min-h-0 flex">
            <aside class="w-64 border-r border-border-default p-3 flex flex-col gap-2 shrink-0">
                <div class="flex items-center justify-between">
                    <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Sessions') }}</span>
                    <x-ui.button variant="ghost" size="sm" wire:click="createSession">
                        <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    </x-ui.button>
                </div>

                <div class="flex-1 overflow-y-auto space-y-1">
                    @forelse($sessions as $session)
                        <div class="group flex items-start gap-1">
                            <button
                                wire:key="lara-session-{{ $session->id }}"
                                wire:click="selectSession('{{ $session->id }}')"
                                class="flex-1 text-left px-2 py-1.5 rounded-lg text-sm transition-colors
                                    {{ $selectedSessionId === $session->id ? 'bg-surface-subtle text-ink' : 'text-muted hover:bg-surface-subtle/60 hover:text-ink' }}"
                            >
                                <div class="truncate font-medium">{{ $session->title ?? __('Untitled') }}</div>
                                <div class="text-xs text-muted tabular-nums">{{ $session->lastActivityAt->format('M j, H:i') }}</div>
                            </button>
                            <button
                                type="button"
                                wire:click="deleteSession('{{ $session->id }}')"
                                class="opacity-0 group-hover:opacity-100 transition-opacity text-muted hover:text-ink p-1"
                                title="{{ __('Delete session') }}"
                            >
                                <x-icon name="heroicon-o-trash" class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    @empty
                        <p class="text-sm text-muted py-4 text-center">{{ __('No sessions yet.') }}</p>
                    @endforelse
                </div>
            </aside>

            <section class="flex-1 min-h-0 flex flex-col">
                @if ($selectedSessionId)
                    <div
                        class="flex-1 min-h-0 overflow-y-auto px-4 py-3 space-y-3"
                        x-ref="laraScroll"
                        x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                        x-effect="$nextTick(() => $refs.laraScroll.scrollTop = $refs.laraScroll.scrollHeight)"
                    >
                        @forelse($messages as $message)
                            <div class="flex {{ $message->role === 'user' ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm
                                    {{ $message->role === 'user' ? 'bg-accent text-accent-on' : 'bg-surface-subtle text-ink' }}"
                                >
                                    <div class="whitespace-pre-wrap break-words">{{ $message->content }}</div>
                                    <div class="text-[10px] mt-1 {{ $message->role === 'user' ? 'text-accent-on/70' : 'text-muted' }} tabular-nums">
                                        {{ $message->timestamp->format('H:i:s') }}
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="h-full flex items-center justify-center">
                                <p class="text-sm text-muted">{{ __('Send a message to start chatting with Lara.') }}</p>
                            </div>
                        @endforelse

                        @if ($isLoading)
                            <div class="flex justify-start">
                                <div class="bg-surface-subtle rounded-2xl px-3 py-2">
                                    <div class="flex gap-1">
                                        <span class="w-2 h-2 bg-muted/50 rounded-full animate-pulse"></span>
                                        <span class="w-2 h-2 bg-muted/50 rounded-full animate-pulse" style="animation-delay: 150ms"></span>
                                        <span class="w-2 h-2 bg-muted/50 rounded-full animate-pulse" style="animation-delay: 300ms"></span>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="border-t border-border-default px-4 py-3">
                        <form wire:submit="sendMessage" class="flex items-end gap-2">
                            <div class="flex-1 min-w-0">
                                    <x-ui.input
                                        x-ref="laraComposer"
                                        wire:model="messageInput"
                                        placeholder="{{ __('Ask Lara anything about BLB, or use /delegate <task>...') }}"
                                        autocomplete="off"
                                        :disabled="$isLoading"
                                    />
                            </div>
                            <x-ui.button type="submit" variant="primary" :disabled="$isLoading">
                                <x-icon name="heroicon-o-paper-airplane" class="w-4 h-4" />
                            </x-ui.button>
                        </form>
                    </div>
                @else
                    <div class="h-full flex items-center justify-center">
                        <div class="text-center space-y-2">
                            <p class="text-sm text-muted">{{ __('Create a session to start chatting with Lara.') }}</p>
                            <x-ui.button variant="primary" wire:click="createSession">
                                <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                                {{ __('New Session') }}
                            </x-ui.button>
                        </div>
                    </div>
                @endif
            </section>
        </div>
    @endif
</div>
