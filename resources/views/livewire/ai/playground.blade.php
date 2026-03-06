<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\DigitalWorkerRuntime;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\Employee\Models\Employee;
use Livewire\Volt\Component;

new class extends Component
{
    public string $messageInput = '';

    public ?string $selectedSessionId = null;

    public ?int $selectedEmployeeId = null;

    public bool $isLoading = false;

    /** @var array<string, mixed>|null */
    public ?array $lastRunMeta = null;

    public bool $showLlmConfig = false;

    /** @var list<array{provider: string, model: string, max_tokens: int, temperature: float}> */
    public array $llmModels = [];

    public function mount(): void
    {
        $employee = $this->getDefaultDigitalWorker();

        if ($employee) {
            $this->selectedEmployeeId = $employee->id;

            $sessions = app(SessionManager::class)->list($employee->id);

            if (count($sessions) > 0) {
                $this->selectedSessionId = $sessions[0]->id;
            }
        }
    }

    public function createSession(): void
    {
        if (! $this->selectedEmployeeId) {
            return;
        }

        $session = app(SessionManager::class)->create($this->selectedEmployeeId);
        $this->selectedSessionId = $session->id;
        $this->lastRunMeta = null;
    }

    public function selectSession(string $sessionId): void
    {
        $this->selectedSessionId = $sessionId;
        $this->lastRunMeta = null;
    }

    public function deleteSession(string $sessionId): void
    {
        if (! $this->selectedEmployeeId) {
            return;
        }

        app(SessionManager::class)->delete($this->selectedEmployeeId, $sessionId);

        if ($this->selectedSessionId === $sessionId) {
            $sessions = app(SessionManager::class)->list($this->selectedEmployeeId);
            $this->selectedSessionId = count($sessions) > 0 ? $sessions[0]->id : null;
        }

        $this->lastRunMeta = null;
    }

    public function sendMessage(): void
    {
        if (! $this->selectedEmployeeId || ! $this->selectedSessionId || trim($this->messageInput) === '') {
            return;
        }

        $this->isLoading = true;
        $content = trim($this->messageInput);
        $this->messageInput = '';

        $messageManager = app(MessageManager::class);
        $runtime = app(DigitalWorkerRuntime::class);

        // Append user message
        $messageManager->appendUserMessage(
            $this->selectedEmployeeId,
            $this->selectedSessionId,
            $content,
        );

        // Get conversation history for context
        $messages = $messageManager->read(
            $this->selectedEmployeeId,
            $this->selectedSessionId,
        );

        // Get Digital Worker's job description for system prompt
        $employee = Employee::query()->find($this->selectedEmployeeId);
        if ($employee?->isLara()) {
            $systemPrompt = app(LaraPromptFactory::class)->buildForCurrentUser();
        } else {
            $systemPrompt = $employee?->job_description
                ? __('You are a Digital Worker. Your role: :role', ['role' => $employee->job_description])
                : __('You are a helpful Digital Worker assistant.');
        }

        // Run LLM
        $result = $runtime->run($messages, $this->selectedEmployeeId, $systemPrompt);

        // Append assistant message
        $messageManager->appendAssistantMessage(
            $this->selectedEmployeeId,
            $this->selectedSessionId,
            $result['content'],
            $result['run_id'],
            $result['meta'],
        );

        $this->lastRunMeta = [
            'run_id' => $result['run_id'],
            ...$result['meta'],
        ];

        // Auto-title the session from the first user message
        $sessionManager = app(SessionManager::class);
        $session = $sessionManager->get($this->selectedEmployeeId, $this->selectedSessionId);

        if ($session && $session->title === null) {
            $title = mb_substr($content, 0, 60);

            if (mb_strlen($content) > 60) {
                $title .= '…';
            }

            $sessionManager->updateTitle($this->selectedEmployeeId, $this->selectedSessionId, $title);
        }

        $this->isLoading = false;
    }

    public function openLlmConfig(): void
    {
        if (! $this->selectedEmployeeId) {
            return;
        }

        $configResolver = app(ConfigResolver::class);
        $config = $configResolver->readWorkspaceConfig($this->selectedEmployeeId);
        $models = $config['llm']['models'] ?? [];

        if (count($models) === 0) {
            // Seed from company's default provider + model
            $user = auth()->user();
            $companyId = $user?->employee?->company_id ? (int) $user->employee->company_id : null;
            $default = $companyId ? $configResolver->resolveDefault($companyId) : null;

            $this->llmModels = [[
                'provider' => $default['provider_name'] ?? '',
                'model' => $default['model'] ?? '',
                'max_tokens' => (int) ($default['max_tokens'] ?? config('ai.llm.max_tokens', 2048)),
                'temperature' => (float) ($default['temperature'] ?? config('ai.llm.temperature', 0.7)),
            ]];
        } else {
            $this->llmModels = array_map(fn ($m) => [
                'provider' => $m['provider'] ?? '',
                'model' => $m['model'] ?? '',
                'max_tokens' => (int) ($m['max_tokens'] ?? config('ai.llm.max_tokens', 2048)),
                'temperature' => (float) ($m['temperature'] ?? config('ai.llm.temperature', 0.7)),
            ], $models);
        }

        $this->showLlmConfig = true;
    }

    public function addLlmModel(): void
    {
        $this->llmModels[] = [
            'provider' => '',
            'model' => '',
            'max_tokens' => (int) config('ai.llm.max_tokens', 2048),
            'temperature' => (float) config('ai.llm.temperature', 0.7),
        ];
    }

    public function removeLlmModel(int $index): void
    {
        if (count($this->llmModels) <= 1) {
            return;
        }

        array_splice($this->llmModels, $index, 1);
        $this->llmModels = array_values($this->llmModels);
    }

    public function moveLlmModel(int $index, string $direction): void
    {
        $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;

        if ($targetIndex < 0 || $targetIndex >= count($this->llmModels)) {
            return;
        }

        [$this->llmModels[$index], $this->llmModels[$targetIndex]] =
            [$this->llmModels[$targetIndex], $this->llmModels[$index]];
        $this->llmModels = array_values($this->llmModels);
    }

    /**
     * Auto-select the provider's default model when the provider dropdown changes.
     *
     * Livewire calls updated{Property} whenever a bound property changes.
     * The key format is "llmModels.{index}.provider".
     *
     * @param  string  $value  New provider name
     */
    public function updatedLlmModels(mixed $value, string $key): void
    {
        if (! str_ends_with($key, '.provider')) {
            return;
        }

        // Extract index from key like "0.provider"
        $index = (int) explode('.', $key)[0];

        if (! isset($this->llmModels[$index])) {
            return;
        }

        $providerName = $this->llmModels[$index]['provider'] ?? '';

        if ($providerName === '') {
            $this->llmModels[$index]['model'] = '';

            return;
        }

        // Find the provider's default model
        $user = auth()->user();
        $companyId = $user?->employee?->company_id ? (int) $user->employee->company_id : null;

        if ($companyId === null) {
            return;
        }

        $provider = \App\Modules\Core\AI\Models\AiProvider::query()
            ->forCompany($companyId)
            ->active()
            ->where('name', $providerName)
            ->first();

        if (! $provider) {
            return;
        }

        $defaultModel = \App\Modules\Core\AI\Models\AiProviderModel::query()
            ->where('ai_provider_id', $provider->id)
            ->active()
            ->default()
            ->first();

        if (! $defaultModel) {
            $defaultModel = \App\Modules\Core\AI\Models\AiProviderModel::query()
                ->where('ai_provider_id', $provider->id)
                ->active()
                ->orderBy('model_id')
                ->first();
        }

        $this->llmModels[$index]['model'] = $defaultModel?->model_id ?? '';
    }

    public function saveLlmConfig(): void
    {
        if (! $this->selectedEmployeeId) {
            return;
        }

        $this->validate([
            'llmModels.*.provider' => ['required', 'string'],
            'llmModels.*.model' => ['required', 'string'],
        ], [
            'llmModels.*.provider.required' => __('Provider is required.'),
            'llmModels.*.model.required' => __('Model is required.'),
        ]);

        $configResolver = app(ConfigResolver::class);
        $existingConfig = $configResolver->readWorkspaceConfig($this->selectedEmployeeId) ?? [];

        $models = array_map(function ($m) {
            $entry = ['model' => $m['model']];

            if (! empty($m['provider'])) {
                $entry['provider'] = $m['provider'];
            }

            if (isset($m['max_tokens'])) {
                $entry['max_tokens'] = (int) $m['max_tokens'];
            }

            if (isset($m['temperature'])) {
                $entry['temperature'] = (float) $m['temperature'];
            }

            return $entry;
        }, $this->llmModels);

        $existingConfig['llm'] = ['models' => $models];
        $configResolver->writeWorkspaceConfig($this->selectedEmployeeId, $existingConfig);

        $this->showLlmConfig = false;
    }

    public function with(): array
    {
        $sessions = [];
        $messages = [];
        $digitalWorkers = collect();
        $availableProviders = collect();
        $providerModelsMap = [];

        $user = auth()->user();

        if ($user) {
            // Get Digital Workers supervised by the current user's employee record
            $userEmployee = $user->employee;

            if ($userEmployee) {
                $digitalWorkers = Employee::query()
                    ->digitalWorker()
                    ->where('supervisor_id', $userEmployee->id)
                    ->active()
                    ->get();
            }

            if ($user->employee && $user->employee->company_id) {
                $availableProviders = \App\Modules\Core\AI\Models\AiProvider::query()
                    ->forCompany((int) $user->employee->company_id)
                    ->active()
                    ->get(['id', 'name', 'display_name']);

                // Build provider → models map for the LLM config modal
                $providerModelsMap = [];
                foreach ($availableProviders as $p) {
                    $providerModelsMap[$p->name] = \App\Modules\Core\AI\Models\AiProviderModel::query()
                        ->where('ai_provider_id', $p->id)
                        ->active()
                        ->orderBy('model_id')
                        ->pluck('model_id')
                        ->all();
                }
            }
        }

        if ($this->selectedEmployeeId) {
            $sessions = app(SessionManager::class)->list($this->selectedEmployeeId);
        }

        if ($this->selectedEmployeeId && $this->selectedSessionId) {
            $messages = app(MessageManager::class)->read(
                $this->selectedEmployeeId,
                $this->selectedSessionId,
            );
        }

        return [
            'digitalWorkers' => $digitalWorkers,
            'sessions' => $sessions,
            'messages' => $messages,
            'availableProviders' => $availableProviders,
            'providerModelsMap' => $providerModelsMap,
        ];
    }

    public function selectDigitalWorker(int $employeeId): void
    {
        $this->selectedEmployeeId = $employeeId;
        $this->selectedSessionId = null;
        $this->lastRunMeta = null;

        $sessions = app(SessionManager::class)->list($employeeId);

        if (count($sessions) > 0) {
            $this->selectedSessionId = $sessions[0]->id;
        }
    }

    /**
     * Get the default Digital Worker for the current user.
     */
    private function getDefaultDigitalWorker(): ?Employee
    {
        $user = auth()->user();

        if (! $user || ! $user->employee) {
            return null;
        }

        return Employee::query()
            ->digitalWorker()
            ->where('supervisor_id', $user->employee->id)
            ->active()
            ->first();
    }
}; ?>

<div>
    <x-slot name="title">{{ __('Digital Worker Playground') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Digital Worker Playground')" />

        {{-- Digital Worker Tabs --}}
        @if($digitalWorkers->count() > 0)
            <div class="flex gap-1 border-b border-border-default items-center">
                @foreach($digitalWorkers as $dw)
                    <button
                        wire:key="dw-tab-{{ $dw->id }}"
                        wire:click="selectDigitalWorker({{ $dw->id }})"
                        class="px-4 py-2 text-sm font-medium transition-colors relative
                            {{ $selectedEmployeeId === $dw->id
                                ? 'text-ink'
                                : 'text-muted hover:text-ink' }}"
                    >
                        {{ $dw->displayName() }}
                        @if($selectedEmployeeId === $dw->id)
                            <span class="absolute bottom-0 inset-x-0 h-0.5 bg-accent rounded-full"></span>
                        @endif
                    </button>
                @endforeach

                @if($selectedEmployeeId)
                    <button
                        wire:click="openLlmConfig"
                        class="ml-auto px-2 py-2 text-muted hover:text-ink transition-colors"
                        title="{{ __('LLM Configuration') }}"
                    >
                        <x-icon name="heroicon-o-cog-6-tooth" class="w-4 h-4" />
                    </button>
                @endif
            </div>
        @endif

        <div class="flex gap-3 h-[calc(100vh-12rem)]">
            {{-- Left Panel: Session list --}}
            <div class="w-64 flex-shrink-0 flex flex-col">
                <x-ui.card class="flex-1 overflow-hidden flex flex-col">
                    <div class="flex items-center justify-between mb-2">
                        <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Sessions') }}</span>
                        <x-ui.button variant="ghost" size="sm" wire:click="createSession" :disabled="!$selectedEmployeeId">
                            <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                        </x-ui.button>
                    </div>

                    <div class="flex-1 overflow-y-auto space-y-1 -mx-card-inner px-card-inner">
                        @forelse($sessions as $session)
                            <button
                                wire:key="session-{{ $session->id }}"
                                wire:click="selectSession('{{ $session->id }}')"
                                class="w-full text-left px-2 py-1.5 rounded-lg text-sm transition-colors group
                                    {{ $selectedSessionId === $session->id ? 'bg-surface-subtle text-ink' : 'text-muted hover:bg-surface-subtle/50 hover:text-ink' }}"
                            >
                                <div class="truncate font-medium">{{ $session->title ?? __('Untitled') }}</div>
                                <div class="text-xs text-muted tabular-nums">{{ $session->lastActivityAt->format('M j, H:i') }}</div>
                            </button>
                        @empty
                            <p class="text-sm text-muted py-4 text-center">{{ __('No sessions yet.') }}</p>
                        @endforelse
                    </div>
                </x-ui.card>
            </div>

            {{-- Main Panel: Chat --}}
            <div class="flex-1 flex flex-col overflow-hidden bg-surface-card border border-border-default rounded-2xl shadow-sm">
                @if($selectedSessionId)
                    {{-- Messages --}}
                    <div
                        class="flex-1 overflow-y-auto min-h-0 space-y-4 px-card-inner py-2"
                        x-ref="chatScroll"
                        x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                        x-effect="$nextTick(() => $refs.chatScroll.scrollTop = $refs.chatScroll.scrollHeight)"
                    >
                        @forelse($messages as $message)
                            <div class="flex {{ $message->role === 'user' ? 'justify-end' : 'justify-start' }}">
                                <div class="max-w-[75%] rounded-2xl px-3 py-2 text-sm
                                    {{ $message->role === 'user'
                                        ? 'bg-accent text-accent-on'
                                        : 'bg-surface-subtle text-ink' }}"
                                >
                                    <div class="whitespace-pre-wrap break-words">{{ $message->content }}</div>
                                    <div class="text-[10px] mt-1 {{ $message->role === 'user' ? 'text-accent-on/70' : 'text-muted' }} tabular-nums">
                                        {{ $message->timestamp->format('H:i:s') }}
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="flex-1 flex items-center justify-center h-full">
                                <p class="text-sm text-muted">{{ __('Send a message to start the conversation.') }}</p>
                            </div>
                        @endforelse

                        @if($isLoading)
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

                    {{-- Composer --}}
                    <div class="border-t border-border-default pt-2 px-card-inner pb-card-inner">
                        <form wire:submit="sendMessage" class="flex gap-2 items-end">
                            <div class="flex-1 min-w-0">
                                <x-ui.input
                                    wire:model="messageInput"
                                    placeholder="{{ __('Type a message...') }}"
                                    autocomplete="off"
                                    :disabled="$isLoading"
                                />
                            </div>
                            <x-ui.button
                                type="submit"
                                variant="primary"
                                :disabled="$isLoading"
                            >
                                <x-icon name="heroicon-o-paper-airplane" class="w-4 h-4" />
                            </x-ui.button>
                        </form>
                    </div>
                @else
                    <div class="flex-1 flex items-center justify-center">
                        <div class="text-center space-y-2">
                            <p class="text-sm text-muted">
                                @if($selectedEmployeeId)
                                    {{ __('Create a session to start chatting.') }}
                                @else
                                    {{ __('No Digital Worker available. Assign one to your supervision first.') }}
                                @endif
                            </p>
                            @if($selectedEmployeeId)
                                <x-ui.button variant="primary" wire:click="createSession">
                                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                                    {{ __('New Session') }}
                                </x-ui.button>
                            @endif
                        </div>
                    </div>
                @endif
            </div>

            {{-- Right Panel: Debug --}}
            <div class="w-56 flex-shrink-0">
                <x-ui.card>
                    <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Debug') }}</span>

                    @if($lastRunMeta)
                        <dl class="mt-2 space-y-1.5 text-xs">
                            <div>
                                <dt class="text-muted">{{ __('Run ID') }}</dt>
                                <dd class="text-ink font-mono tabular-nums">{{ $lastRunMeta['run_id'] ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted">{{ __('Model') }}</dt>
                                <dd class="text-ink">{{ $lastRunMeta['model'] ?? '-' }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted">{{ __('Latency') }}</dt>
                                <dd class="text-ink tabular-nums">{{ isset($lastRunMeta['latency_ms']) ? $lastRunMeta['latency_ms'].'ms' : '-' }}</dd>
                            </div>
                            @if(isset($lastRunMeta['tokens']))
                                <div>
                                    <dt class="text-muted">{{ __('Tokens') }}</dt>
                                    <dd class="text-ink tabular-nums">
                                        {{ $lastRunMeta['tokens']['prompt'] ?? '?' }} → {{ $lastRunMeta['tokens']['completion'] ?? '?' }}
                                    </dd>
                                </div>
                            @endif
                            @if(isset($lastRunMeta['error']))
                                <div>
                                    <dt class="text-muted">{{ __('Error') }}</dt>
                                    <dd class="text-status-danger">{{ $lastRunMeta['error'] }}</dd>
                                </div>
                            @endif
                            @if(!empty($lastRunMeta['fallback_attempts']))
                                <div x-data="{ open: false }" class="pt-1 border-t border-border-default">
                                    <button @click="open = !open" class="flex items-center gap-1 text-muted hover:text-ink transition-colors w-full text-left">
                                        <span class="text-[10px]" x-text="open ? '▾' : '▸'"></span>
                                        <dt class="text-muted">{{ __('Fallback Attempts') }} ({{ count($lastRunMeta['fallback_attempts']) }})</dt>
                                    </button>
                                    <div x-show="open" x-cloak class="mt-1 space-y-1.5">
                                        @foreach($lastRunMeta['fallback_attempts'] as $i => $attempt)
                                            <div class="rounded-lg bg-surface-overlay p-1.5 text-[11px]">
                                                <div class="text-muted">#{{ $i + 1 }} {{ $attempt['provider'] ?? '-' }} / {{ $attempt['model'] ?? '-' }}</div>
                                                <div class="text-status-danger">{{ $attempt['error'] ?? '-' }}</div>
                                                <div class="text-muted tabular-nums">{{ $attempt['error_type'] ?? '-' }} · {{ ($attempt['latency_ms'] ?? 0) }}ms</div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </dl>
                    @else
                        <p class="mt-2 text-xs text-muted">{{ __('Send a message to see runtime metadata.') }}</p>
                    @endif
                </x-ui.card>
            </div>
        </div>
    </div>

    {{-- LLM Configuration Modal --}}
    <x-ui.modal wire:model="showLlmConfig" class="max-w-2xl">
        <div class="p-card-inner">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium tracking-tight text-ink">{{ __('LLM Configuration') }}</h3>
                <button wire:click="$set('showLlmConfig', false)" class="text-muted hover:text-ink">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <p class="text-sm text-muted mb-4">{{ __('Configure LLM models in priority order. The first model is primary; subsequent models are used as fallbacks on transient failures.') }}</p>

            <div class="space-y-3">
                @foreach($llmModels as $index => $llmModel)
                    <div wire:key="llm-model-{{ $index }}" class="border border-border-default rounded-2xl p-card-inner">
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">
                                {{ $index === 0 ? __('Primary Model') : __('Fallback :n', ['n' => $index]) }}
                            </span>
                            <div class="flex items-center gap-1">
                                <button
                                    wire:click="moveLlmModel({{ $index }}, 'up')"
                                    class="text-muted hover:text-ink p-1 rounded disabled:opacity-50 disabled:cursor-not-allowed"
                                    @if($index === 0) disabled @endif
                                    title="{{ __('Move Up') }}"
                                >
                                    <x-icon name="heroicon-m-chevron-down" class="w-4 h-4 rotate-180" />
                                </button>
                                <button
                                    wire:click="moveLlmModel({{ $index }}, 'down')"
                                    class="text-muted hover:text-ink p-1 rounded disabled:opacity-50 disabled:cursor-not-allowed"
                                    @if($index === count($llmModels) - 1) disabled @endif
                                    title="{{ __('Move Down') }}"
                                >
                                    <x-icon name="heroicon-m-chevron-down" class="w-4 h-4" />
                                </button>
                                <button
                                    wire:click="removeLlmModel({{ $index }})"
                                    class="text-muted hover:text-ink p-1 rounded disabled:opacity-50 disabled:cursor-not-allowed"
                                    @if(count($llmModels) <= 1) disabled @endif
                                    title="{{ __('Remove') }}"
                                >
                                    <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <x-ui.select
                                wire:model.live="llmModels.{{ $index }}.provider"
                                label="{{ __('Provider') }}"
                            >
                                <option value="">{{ __('Select provider') }}</option>
                                @foreach($availableProviders as $provider)
                                    <option value="{{ $provider->name }}">{{ $provider->display_name }}</option>
                                @endforeach
                            </x-ui.select>

                            @php
                                $selectedProvider = $llmModel['provider'] ?? '';
                                $modelOptions = $providerModelsMap[$selectedProvider] ?? [];
                            @endphp
                            <x-ui.select
                                wire:model="llmModels.{{ $index }}.model"
                                label="{{ __('Model') }}"
                                :disabled="$selectedProvider === ''"
                            >
                                @if($selectedProvider === '')
                                    <option value="">{{ __('Select provider first') }}</option>
                                @else
                                    <option value="">{{ __('Select model') }}</option>
                                    @foreach($modelOptions as $modelId)
                                        <option value="{{ $modelId }}">{{ $modelId }}</option>
                                    @endforeach
                                @endif
                            </x-ui.select>

                            <x-ui.input
                                wire:model="llmModels.{{ $index }}.max_tokens"
                                type="number"
                                min="1"
                                label="{{ __('Max Tokens') }}"
                            />

                            <x-ui.input
                                wire:model="llmModels.{{ $index }}.temperature"
                                type="number"
                                step="0.1"
                                min="0"
                                max="2"
                                label="{{ __('Temperature') }}"
                            />
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-3">
                <x-ui.button variant="ghost" wire:click="addLlmModel">
                    <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                    {{ __('Add Fallback Model') }}
                </x-ui.button>
            </div>

            <div class="flex justify-end gap-2 mt-4 pt-4 border-t border-border-default">
                <x-ui.button variant="ghost" wire:click="$set('showLlmConfig', false)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="primary" wire:click="saveLlmConfig">{{ __('Save Configuration') }}</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
