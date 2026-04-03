<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Chat $this */
?>
<div
    class="h-full flex flex-col"
    x-data="{
        sessionsOpen: false,
        sessionWidth: parseInt(localStorage.getItem('agent-chat-session-width')) || 224,
        pageAwareness: localStorage.getItem('blb-lara-page-awareness') || 'page',
        _sessionDragging: false,
        SESSION_MIN: 160,
        SESSION_MAX: 320,

        cyclePageAwareness() {
            const levels = ['off', 'page', 'full'];
            const idx = levels.indexOf(this.pageAwareness);
            this.pageAwareness = levels[(idx + 1) % levels.length];
            localStorage.setItem('blb-lara-page-awareness', this.pageAwareness);
        },

        startSessionDrag(e) {
            this._sessionDragging = true;
            const startX = e.clientX;
            const startWidth = this.sessionWidth;
            document.documentElement.style.cursor = 'col-resize';
            document.documentElement.style.userSelect = 'none';

            const onMove = (e) => {
                const delta = e.clientX - startX;
                this.sessionWidth = Math.max(this.SESSION_MIN, Math.min(this.SESSION_MAX, startWidth + delta));
            };

            const onUp = () => {
                this._sessionDragging = false;
                document.documentElement.style.cursor = '';
                document.documentElement.style.userSelect = '';
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                localStorage.setItem('agent-chat-session-width', this.sessionWidth);
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        }
    }"
    x-init="
        $nextTick(() => $refs.agentComposer?.focus());
        $wire.set('pageUrl', window.location.href);
        $watch('pageAwareness', v => $wire.set('pageAwareness', v));
    "
    @navigate.window="$wire.set('pageUrl', window.location.href)"
    @agent-chat-focus-composer.window="$nextTick(() => $refs.agentComposer?.focus())"
    @agent-chat-opened.window="if ($event.detail?.prompt) { $wire.set('messageInput', $event.detail.prompt); $nextTick(() => $refs.agentComposer?.focus()); }"
>
    {{-- Header bar --}}
    <div class="h-11 px-4 border-b border-border-default bg-surface-bar flex items-center justify-between shrink-0">
        <div class="flex items-center gap-2">
            <button
                type="button"
                x-on:click="sessionsOpen = !sessionsOpen"
                class="text-muted hover:text-ink transition-colors p-0.5"
                :title="sessionsOpen ? '{{ __('Hide sessions') }}' : '{{ __('Show sessions') }}'"
                :aria-label="sessionsOpen ? '{{ __('Hide sessions') }}' : '{{ __('Show sessions') }}'"
                :aria-expanded="sessionsOpen"
            >
                <x-icon name="heroicon-o-chat-bubble-left-right" class="w-4 h-4" />
            </button>
            @if ($settingsUrl !== null)
                <a
                    href="{{ $settingsUrl }}"
                    wire:navigate
                    class="text-ink hover:text-accent transition-colors"
                    title="{{ __('Open Lara settings') }}"
                    aria-label="{{ __('Open Lara settings') }}"
                >
                    <x-ai.agent-identity
                        :name="$agentIdentity['name']"
                        :role="$agentIdentity['role']"
                        :icon="$agentIdentity['icon']"
                        :show-role="false"
                    />
                </a>
            @else
                <x-ai.agent-identity
                    :name="$agentIdentity['name']"
                    :role="$agentIdentity['role']"
                    :icon="$agentIdentity['icon']"
                    :show-role="false"
                />
            @endif
        </div>

        <div class="flex items-center gap-1">
            {{-- Page awareness toggle --}}
            <button
                type="button"
                x-on:click="cyclePageAwareness()"
                class="text-muted hover:text-ink transition-colors p-0.5"
                :title="pageAwareness === 'off'
                    ? '{{ __('Page awareness: off') }}'
                    : (pageAwareness === 'page'
                        ? '{{ __('Page awareness: page info') }}'
                        : '{{ __('Page awareness: full snapshot') }}')"
                :aria-label="pageAwareness === 'off'
                    ? '{{ __('Page awareness off — click to enable') }}'
                    : (pageAwareness === 'page'
                        ? '{{ __('Page awareness: page info — click for full') }}'
                        : '{{ __('Page awareness: full snapshot — click to disable') }}')"
            >
                <x-icon name="heroicon-o-eye-slash" class="w-4 h-4" x-show="pageAwareness === 'off'" x-cloak />
                <x-icon name="heroicon-o-eye" class="w-4 h-4" x-show="pageAwareness === 'page'" x-cloak
                    ::class="'text-muted hover:text-ink'" />
                <x-icon name="heroicon-o-eye" class="w-4 h-4 text-accent" x-show="pageAwareness === 'full'" x-cloak />
            </button>

            {{-- Keyboard shortcuts cheatsheet --}}
            <div x-data="{ shortcutsOpen: false, _mod: navigator.platform?.includes('Mac') ? '⌘' : 'Ctrl' }" class="relative">
                <x-ui.help
                    size="md"
                    icon="heroicon-o-keyboard-keys"
                    x-on:click="shortcutsOpen = !shortcutsOpen"
                    title="{{ __('Keyboard shortcuts') }}"
                    aria-label="{{ __('Keyboard shortcuts') }}"
                />
                <div
                    x-show="shortcutsOpen"
                    x-on:click.outside="shortcutsOpen = false"
                    x-on:keydown.escape.window="shortcutsOpen = false"
                    x-transition.opacity.duration.100ms
                    x-cloak
                    class="absolute right-0 top-full mt-1 w-56 bg-surface-card border border-border-default rounded-xl shadow-lg z-30 p-3 space-y-1.5"
                >
                    <div class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-2">{{ __('Shortcuts') }}</div>
                    <template x-for="s in [
                        { keys: _mod + '+K', label: '{{ __('Toggle chat') }}' },
                        { keys: _mod + '+Shift+K', label: '{{ __('Toggle docked mode') }}' },
                        { keys: _mod + '+Shift+F', label: '{{ __('Toggle fullscreen mode') }}' },
                        { keys: 'Escape', label: '{{ __('Close chat') }}' },
                        { keys: 'Enter', label: '{{ __('Send message') }}' },
                        { keys: 'Shift+Enter', label: '{{ __('New line') }}' },
                    ]">
                        <div class="flex items-center justify-between gap-2 text-xs">
                            <span class="text-ink" x-text="s.label"></span>
                            <kbd class="shrink-0 px-1.5 py-0.5 rounded bg-surface-subtle border border-border-default text-[10px] font-mono text-muted" x-text="s.keys"></kbd>
                        </div>
                    </template>
                </div>
            </div>
            {{-- Dock/undock toggle (desktop only) --}}
            <button
                type="button"
                x-on:click="$dispatch('toggle-agent-chat-mode')"
                class="hidden sm:inline-flex text-muted hover:text-ink transition-colors p-0.5"
                title="{{ __('Toggle docked mode') }}"
                aria-label="{{ __('Toggle docked mode') }}"
            >
                <x-icon name="heroicon-o-dock-right" class="w-4 h-4" x-show="laraChatMode === 'overlay'" x-cloak />
                <x-icon name="heroicon-o-undock-overlay" class="w-4 h-4" x-show="laraChatMode === 'docked'" x-cloak />
            </button>
            <button
                type="button"
                x-on:click="$dispatch('toggle-agent-chat-fullscreen')"
                class="hidden sm:inline-flex text-muted hover:text-ink transition-colors p-0.5"
                title="{{ __('Toggle fullscreen mode') }}"
                aria-label="{{ __('Toggle fullscreen mode') }}"
            >
                <x-icon name="heroicon-o-fullscreen" class="w-4 h-4" x-show="!laraChatFullscreen" x-cloak />
                <x-icon name="heroicon-o-fullscreen-exit" class="w-4 h-4" x-show="laraChatFullscreen" x-cloak />
            </button>
            <button
                type="button"
                x-on:click="$dispatch('close-agent-chat')"
                class="text-muted hover:text-ink transition-colors p-0.5"
                title="{{ __('Close chat') }}"
                aria-label="{{ __('Close chat') }}"
            >
                <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
            </button>
        </div>
    </div>

    @if (! $agentExists)
        <div class="p-4">
            <x-ui.alert variant="warning">
                {{ __(':name has not been provisioned yet.', ['name' => $agentIdentity['name']]) }}
                @if ($settingsUrl !== null)
                    <a href="{{ $settingsUrl }}" wire:navigate class="text-accent hover:underline">
                        {{ __('Set up :name', ['name' => $agentIdentity['name']]) }}
                    </a>
                @endif
            </x-ui.alert>
        </div>
    @elseif (! $agentActivated)
        <div class="p-4">
            <x-ui.alert variant="info">
                {{ __(':name is not activated yet. Configure an AI provider to start chatting.', ['name' => $agentIdentity['name']]) }}
                @if ($settingsUrl !== null)
                    <a href="{{ $settingsUrl }}" wire:navigate class="text-accent hover:underline">
                        {{ __('Activate :name', ['name' => $agentIdentity['name']]) }}
                    </a>
                @endif
            </x-ui.alert>
        </div>
    @else
        <div class="flex-1 min-h-0 flex">
            {{-- Session sidebar (inline, drag-resizable) --}}
            <aside
                x-show="sessionsOpen"
                x-cloak
                class="shrink-0 bg-surface-card border-r border-border-default p-3 flex flex-col gap-2 relative"
                :style="'width: ' + sessionWidth + 'px'"
            >
                <div class="flex items-center justify-between">
                    <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Sessions') }}</span>
                    <div class="flex items-center gap-1">
                        <x-ui.button variant="ghost" size="sm" wire:click="createSession">
                            <x-icon name="heroicon-o-plus" class="w-4 h-4" />
                        </x-ui.button>
                        <button
                            type="button"
                            x-on:click="sessionsOpen = false"
                            class="text-muted hover:text-ink transition-colors p-0.5"
                            title="{{ __('Close sessions') }}"
                            aria-label="{{ __('Close sessions') }}"
                        >
                            <x-icon name="heroicon-o-x-mark" class="w-4 h-4" />
                        </button>
                    </div>
                </div>

                {{-- Session search --}}
                <div class="relative">
                    <x-ui.search-input
                        wire:model.live.debounce.400ms="searchQuery"
                        wire:keydown.enter="searchSessions"
                        placeholder="{{ __('Search conversations...') }}"
                    />
                    @if ($searchQuery !== '')
                        <button
                            type="button"
                            wire:click="clearSearch"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-muted hover:text-ink transition-colors"
                            aria-label="{{ __('Clear search') }}"
                        >
                            <x-icon name="heroicon-o-x-mark" class="w-3.5 h-3.5" />
                        </button>
                    @endif
                </div>

                <div class="flex-1 overflow-y-auto space-y-1">
                @if ($searchQuery !== '' && mb_strlen($searchQuery) >= 2)
                    {{-- Search results --}}
                    @if ($searchResults !== [])
                        @foreach ($searchResults as $result)
                            <button
                                wire:click="selectSession('{{ $result['session_id'] }}')"
                                wire:key="search-{{ $result['session_id'] }}"
                                class="w-full text-left px-2 py-1.5 rounded-lg text-sm transition-colors
                                    {{ $selectedSessionId === $result['session_id'] ? 'bg-surface-subtle text-ink' : 'text-muted hover:bg-surface-subtle/60 hover:text-ink' }}"
                            >
                                <div class="truncate font-medium">{{ $result['title'] ?? __('Untitled') }}</div>
                                <div class="text-xs text-muted line-clamp-2 mt-0.5">{{ $result['snippet'] }}</div>
                            </button>
                        @endforeach
                    @else
                        <p class="text-sm text-muted py-4 text-center">{{ __('No matches found.') }}</p>
                    @endif
                @else
                    {{-- Session list --}}
                    @forelse($sessions as $session)
                        <div class="group" wire:key="agent-session-{{ $session->id }}">
                            @if ($editingSessionId === $session->id)
                                <div class="flex-1 px-2 py-1.5 space-y-1">
                                    <div class="flex items-center gap-1">
                                        <input
                                            type="text"
                                            wire:model="editingTitle"
                                            wire:keydown.enter="saveTitle"
                                            wire:keydown.escape="cancelEditingTitle"
                                            x-init="$nextTick(() => $el.focus())"
                                            class="flex-1 min-w-0 text-sm font-medium bg-surface-default border border-border-default rounded px-1.5 py-0.5 text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                                            placeholder="{{ __('Session title') }}"
                                        />
                                        <button
                                            type="button"
                                            wire:click="generateSessionTitle('{{ $session->id }}')"
                                            class="text-muted hover:text-accent transition-colors p-0.5 shrink-0"
                                            title="{{ __('Suggest a title') }}"
                                            aria-label="{{ __('Suggest a title') }}"
                                        >
                                            <x-icon name="heroicon-o-sparkles" class="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <button type="button" wire:click="saveTitle" class="text-[10px] text-accent hover:underline">{{ __('Save') }}</button>
                                        <span class="text-[10px] text-muted">·</span>
                                        <button type="button" wire:click="cancelEditingTitle" class="text-[10px] text-muted hover:text-ink">{{ __('Cancel') }}</button>
                                    </div>
                                </div>
                            @else
                                <div
                                    wire:click="selectSession('{{ $session->id }}')"
                                    class="w-full flex items-start gap-1 rounded-lg px-2 py-1.5 text-sm transition-colors cursor-pointer
                                        {{ $selectedSessionId === $session->id ? 'bg-surface-subtle text-ink' : 'text-muted hover:bg-surface-subtle/60 hover:text-ink' }}"
                                >
                                    <div class="flex-1 min-w-0">
                                        <button
                                            type="button"
                                            wire:click.stop="startEditingTitle('{{ $session->id }}')"
                                            class="w-full text-left truncate font-medium hover:text-ink"
                                            title="{{ __('Edit title') }}"
                                        >
                                            {{ $session->title ?? __('Untitled') }}<span class="sr-only">, {{ __('edit title') }}</span>
                                        </button>
                                        <div class="text-xs text-muted tabular-nums">{{ $session->lastActivityAt->format('M j, H:i') }}</div>
                                    </div>
                                    <button
                                        type="button"
                                        wire:click.stop="deleteSession('{{ $session->id }}')"
                                        class="text-muted hover:text-ink p-1 shrink-0"
                                        title="{{ __('Delete session') }}"
                                        aria-label="{{ __('Delete session') }}"
                                    >
                                        <x-icon name="heroicon-o-trash" class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-muted py-4 text-center">{{ __('No sessions yet.') }}</p>
                    @endforelse
                @endif
                </div>

                {{-- Drag handle --}}
                <div
                    @mousedown.prevent="startSessionDrag($event)"
                    class="absolute top-0 bottom-0 right-0 w-1 cursor-col-resize z-10 group"
                >
                    <div
                        class="w-full h-full transition-colors"
                        :class="_sessionDragging ? 'bg-accent' : 'group-hover:bg-border-default'"
                    ></div>
                </div>
            </aside>

            {{-- Chat area --}}
            <section class="flex-1 min-w-0 min-h-0 flex flex-col"
                x-data="{
                    pendingMessage: null,
                    streamEntries: [],
                    _eventSource: null,
                    _currentRunId: null,
                    followTail: true,
                }"
                x-on:agent-chat-response-ready.window="pendingMessage = null; streamEntries = []; _currentRunId = null"
            >
                <div
                    class="flex-1 min-w-0 min-h-0 overflow-y-auto px-4 py-3 space-y-3 relative"
                    x-ref="agentScroll"
                    x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                    @scroll.throttle.100ms="
                        const el = $refs.agentScroll;
                        followTail = (el.scrollHeight - el.scrollTop - el.clientHeight) < 50;
                    "
                    x-effect="if (followTail) $nextTick(() => $refs.agentScroll.scrollTop = $refs.agentScroll.scrollHeight)"
                >
                    @forelse($messages as $message)
                        @php
                            $messageProvider = $message->meta['provider_name'] ?? $message->meta['llm']['provider'] ?? null;
                            $messageModel = $message->meta['model'] ?? $message->meta['llm']['model'] ?? null;
                            $messageTokens = $message->meta['tokens'] ?? null;
                            $messageLatencyMs = $message->meta['latency_ms'] ?? null;
                            $messageTimeoutSeconds = $message->meta['timeout_seconds'] ?? null;
                            $messageRetryAttempts = $message->meta['retry_attempts'] ?? null;
                            $messageFallbackAttempts = $message->meta['fallback_attempts'] ?? null;
                            $messageErrorType = $message->meta['error_type'] ?? null;
                            $messageErrorMessage = $message->meta['error'] ?? null;
                            $messageRunStatus = $message->meta['status'] ?? null;
                        @endphp

                        @if ($message->type === 'thinking')
                            <x-ai.activity.thinking :timestamp="$message->timestamp" :active="false" />
                        @elseif ($message->type === 'tool_call')
                            <x-ai.activity.tool-call
                                :tool="$message->meta['tool'] ?? ''"
                                :args-summary="$message->meta['args_summary'] ?? '{}'"
                                status="success"
                            />
                        @elseif ($message->type === 'tool_result')
                            <x-ai.activity.tool-call
                                :tool="$message->meta['tool'] ?? ''"
                                :args-summary="''"
                                :status="$message->meta['status'] ?? 'success'"
                                :duration-ms="$message->meta['duration_ms'] ?? null"
                                :result-preview="$message->meta['result_preview'] ?? ''"
                                :result-length="$message->meta['result_length'] ?? 0"
                                :error-payload="$message->meta['error_payload'] ?? null"
                            />
                        @elseif ($message->type === 'hook_action')
                            <x-ai.activity.hook-action
                                :stage="$message->meta['stage'] ?? 'unknown'"
                                :action="$message->meta['action'] ?? 'unknown'"
                                :tool="$message->meta['tool'] ?? null"
                                :tools-removed="$message->meta['tools_removed'] ?? []"
                                :reason="$message->meta['reason'] ?? null"
                                :source="$message->meta['source'] ?? null"
                                :timestamp="$message->timestamp"
                            />
                        @elseif ($message->role === 'user')
                            <x-ai.activity.user-message :content="$message->content" :timestamp="$message->timestamp" />
                        @elseif ($message->role === 'assistant' && ($message->meta['message_type'] ?? null) === 'error')
                            <x-ai.activity.error
                                :message="$message->content"
                                :error-type="$messageErrorType"
                                :timestamp="$message->timestamp"
                                :run-id="$message->runId"
                                :provider="$messageProvider"
                                :model="$messageModel"
                                :markdown="$markdown"
                            />
                        @elseif ($message->role === 'assistant' && ($message->meta['orchestration']['status'] ?? null) !== null)
                            {{-- Action message (navigation, guide, models, etc.) --}}
                            <div class="flex justify-start">
                                <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm bg-accent/10 text-ink border border-accent/20">
                                    <div class="flex items-center gap-1.5 mb-0.5">
                                        <x-icon name="heroicon-o-bolt" class="w-3.5 h-3.5 text-accent" />
                                        <span class="text-[10px] font-semibold uppercase tracking-wider text-accent">{{ __('Action') }}</span>
                                    </div>
                                    <div class="agent-prose max-w-full overflow-x-auto">{!! $markdown->render($message->content) !!}</div>
                                    <x-ai.message-meta
                                        :timestamp="$message->timestamp"
                                        :provider="$messageProvider"
                                        :model="$messageModel"
                                        :run-id="$message->runId"
                                        :tokens="$messageTokens"
                                        :latency-ms="$messageLatencyMs"
                                        :timeout-seconds="$messageTimeoutSeconds"
                                        :retry-attempts="$messageRetryAttempts"
                                        :fallback-attempts="$messageFallbackAttempts"
                                        :error-type="$messageErrorType"
                                        :error-message="$messageErrorMessage"
                                        :run-status="$messageRunStatus"
                                    />
                                </div>
                            </div>
                        @elseif ($message->role === 'assistant')
                            <x-ai.activity.assistant-result
                                :content="$message->content"
                                :timestamp="$message->timestamp"
                                :run-id="$message->runId"
                                :provider="$messageProvider"
                                :model="$messageModel"
                                :markdown="$markdown"
                                :tokens="$messageTokens"
                                :latency-ms="$messageLatencyMs"
                                :timeout-seconds="$messageTimeoutSeconds"
                                :retry-attempts="$messageRetryAttempts"
                                :fallback-attempts="$messageFallbackAttempts"
                                :run-status="$messageRunStatus"
                            />
                        @endif
                    @empty
                        <div x-show="!pendingMessage" class="h-full flex flex-col items-center justify-center gap-4">
                            <p class="text-sm text-muted">{{ __('Send a message to start chatting with :name.', ['name' => $agentIdentity['name']]) }}</p>
                            @if (count($quickActions) > 0)
                                <div class="flex flex-wrap justify-center gap-2 max-w-sm">
                                    @foreach ($quickActions as $action)
                                        <button
                                            type="button"
                                            x-on:click="$dispatch('open-agent-chat', { prompt: '{{ str_replace("'", "\\'", $action['prompt']) }}' })"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-border-default bg-surface-card text-xs text-muted hover:text-ink hover:border-accent/40 hover:bg-surface-subtle transition-all duration-200"
                                        >
                                            <x-icon :name="$action['icon']" class="w-3.5 h-3.5" />
                                            <span>{{ $action['label'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforelse

                    {{-- Optimistic user message shown while Livewire processes --}}
                    <template x-if="pendingMessage">
                        <div class="flex justify-end">
                            <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm bg-accent text-accent-on">
                                <div class="whitespace-pre-wrap break-words" x-text="pendingMessage"></div>
                            </div>
                        </div>
                    </template>

                    {{-- Live stream activity entries --}}
                    <template x-for="(entry, idx) in streamEntries" :key="idx">
                        <div>
                            {{-- Thinking --}}
                            <template x-if="entry.type === 'thinking'">
                                <div class="flex gap-2 py-1">
                                    <div class="shrink-0 mt-0.5">
                                        <x-icon name="heroicon-o-light-bulb" class="w-4 h-4 text-muted" />
                                    </div>
                                    <div class="flex items-center gap-1.5 text-xs text-muted">
                                        <template x-if="entry.active">
                                            <span class="w-2 h-2 bg-accent rounded-full animate-pulse"></span>
                                        </template>
                                        <span>{{ __('Thinking…') }}</span>
                                    </div>
                                </div>
                            </template>

                            {{-- Tool call --}}
                            <template x-if="entry.type === 'tool_call'">
                                <div class="flex gap-2 py-1">
                                    <div class="shrink-0 mt-0.5">
                                        <x-icon name="heroicon-o-wrench-screwdriver" class="w-4 h-4 text-accent" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div x-data="{ expanded: false }" class="rounded-lg border border-border-default bg-surface-card">
                                            <button
                                                type="button"
                                                @click="expanded = !expanded"
                                                class="w-full flex items-center gap-2 px-2.5 py-1.5 text-left text-xs hover:bg-surface-subtle/50 transition-colors rounded-lg"
                                                :aria-expanded="expanded"
                                            >
                                                <span class="font-medium text-ink truncate" x-text="entry.tool"></span>
                                                <span class="text-muted truncate max-w-[10rem]" x-text="entry.argsSummary ? entry.argsSummary.substring(0, 60) : ''"></span>
                                                <span class="ml-auto flex items-center gap-1.5 shrink-0">
                                                    <template x-if="entry.status === 'running'">
                                                        <span class="w-2 h-2 bg-accent rounded-full animate-pulse"></span>
                                                    </template>
                                                    <template x-if="entry.durationMs !== undefined">
                                                        <span class="tabular-nums text-muted" x-text="(entry.durationMs / 1000).toFixed(1) + 's'"></span>
                                                    </template>
                                                    <template x-if="entry.status === 'success'">
                                                        <span class="inline-flex items-center rounded-full bg-emerald-500/10 px-1.5 py-0.5 text-[9px] font-medium text-emerald-700 dark:text-emerald-400">{{ __('Done') }}</span>
                                                    </template>
                                                    <template x-if="entry.status === 'error'">
                                                        <span class="inline-flex items-center rounded-full bg-red-500/10 px-1.5 py-0.5 text-[9px] font-medium text-red-600 dark:text-red-400">{{ __('Error') }}</span>
                                                    </template>
                                                    <template x-if="entry.status === 'denied'">
                                                        <span class="inline-flex items-center rounded-full bg-amber-500/10 px-1.5 py-0.5 text-[9px] font-medium text-amber-700 dark:text-amber-400">{{ __('Denied') }}</span>
                                                    </template>
                                                    <x-icon
                                                        name="heroicon-o-chevron-right"
                                                        class="w-3 h-3 text-muted transition-transform"
                                                        ::class="expanded ? 'rotate-90' : ''"
                                                    />
                                                </span>
                                            </button>
                                            <div x-show="expanded" x-cloak x-collapse class="border-t border-border-default px-2.5 py-2 text-xs">
                                                <template x-if="entry.errorPayload">
                                                    <div class="space-y-1 text-red-500">
                                                        <div x-show="entry.errorPayload?.code"><span class="font-medium">{{ __('Code') }}:</span> <span x-text="entry.errorPayload?.code"></span></div>
                                                        <div x-text="entry.errorPayload?.message"></div>
                                                    </div>
                                                </template>
                                                <template x-if="!entry.errorPayload && entry.resultPreview">
                                                    <div>
                                                        <div class="text-muted whitespace-pre-wrap break-words" x-text="entry.resultPreview"></div>
                                                        <div x-show="entry.resultLength > 200" class="mt-1 text-muted/70 tabular-nums" x-text="entry.resultLength.toLocaleString() + ' {{ __('chars total') }}'"></div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            {{-- Hook action (policy enforcement) --}}
                            <template x-if="entry.type === 'hook_action'">
                                <div class="flex gap-2 py-1">
                                    <div class="shrink-0 mt-0.5">
                                        <x-icon name="heroicon-o-shield-check" class="w-4 h-4 text-amber-500/70" />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-1.5 text-xs text-muted">
                                            <template x-if="entry.action === 'tools_removed'">
                                                <span x-text="(entry.toolsRemoved?.length || 0) + ' tool(s) hidden by policy'"></span>
                                            </template>
                                            <template x-if="entry.action === 'tool_denied'">
                                                <span x-text="(entry.tool || 'Tool') + ' denied'"></span>
                                            </template>
                                            <template x-if="entry.source">
                                                <span class="inline-flex items-center rounded-full bg-amber-500/10 px-1.5 py-0.5 text-[9px] font-medium text-amber-700 dark:text-amber-400" x-text="entry.source === 'authorization' ? '{{ __('authz') }}' : '{{ __('hook') }}'"></span>
                                            </template>
                                            <template x-if="entry.reason">
                                                <span class="text-muted/70 truncate max-w-[16rem]" x-text="'— ' + entry.reason"></span>
                                            </template>
                                        </div>
                                        <template x-if="entry.action === 'tools_removed' && entry.toolsRemoved?.length">
                                            <div class="mt-0.5 text-[10px] text-muted/60 truncate" x-text="entry.toolsRemoved.join(', ')"></div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- Streaming assistant text (drafting state) --}}
                            <template x-if="entry.type === 'assistant_streaming'">
                                <div class="flex justify-start">
                                    <div class="max-w-[90%] text-sm text-ink">
                                        <div class="relative">
                                            <div class="agent-prose max-w-full overflow-x-auto whitespace-pre-wrap break-words opacity-90" x-text="entry.content"></div>
                                            <div class="inline-flex items-center gap-1 mt-1 text-[10px] text-muted">
                                                <span class="w-1.5 h-1.5 bg-accent rounded-full animate-pulse"></span>
                                                <span>{{ __('Writing…') }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            {{-- Error --}}
                            <template x-if="entry.type === 'error'">
                                <div class="flex justify-start">
                                    <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm bg-red-500/10 text-ink border border-red-500/20">
                                        <div class="flex items-center gap-1.5 mb-0.5">
                                            <x-icon name="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5 text-red-500" />
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-red-500">{{ __('Error') }}</span>
                                        </div>
                                        <div class="whitespace-pre-wrap break-words" x-text="entry.message"></div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Loading dots: shown while waiting for non-streaming response --}}
                    <div x-show="pendingMessage && streamEntries.length === 0" x-cloak class="flex justify-start">
                        <div class="bg-surface-subtle rounded-2xl px-3 py-2">
                            <div class="flex gap-1">
                                <span class="w-2 h-2 bg-muted/50 rounded-full animate-pulse"></span>
                                <span class="w-2 h-2 bg-muted/50 rounded-full animate-pulse" style="animation-delay: 150ms"></span>
                                <span class="w-2 h-2 bg-muted/50 rounded-full animate-pulse" style="animation-delay: 300ms"></span>
                            </div>
                        </div>
                    </div>

                    {{-- Background run progress banner --}}
                    @if ($backgroundDispatchId)
                        <div
                            x-data="{
                                status: 'queued',
                                label: '{{ __('Queued…') }}',
                                finished: false,
                                error: null,
                                polling: null,
                                init() {
                                    this.polling = setInterval(() => this.poll(), 3000);
                                },
                                async poll() {
                                    const result = await $wire.pollBackgroundChat();
                                    this.status = result.status;
                                    this.label = result.label;
                                    this.error = result.error;
                                    this.finished = result.finished;
                                    if (this.finished) {
                                        clearInterval(this.polling);
                                        this.polling = null;
                                        if (this.status === 'succeeded') {
                                            $wire.$refresh();
                                        }
                                    }
                                },
                                destroy() {
                                    if (this.polling) clearInterval(this.polling);
                                },
                            }"
                            class="flex justify-start"
                        >
                            <div class="bg-surface-subtle border border-border-default rounded-xl px-4 py-3 max-w-md space-y-2">
                                <div class="flex items-center gap-2">
                                    <template x-if="!finished">
                                        <span class="inline-block w-2 h-2 bg-info rounded-full animate-pulse"></span>
                                    </template>
                                    <template x-if="finished && status === 'succeeded'">
                                        <x-icon name="heroicon-o-check-circle" class="w-4 h-4 text-success" />
                                    </template>
                                    <template x-if="finished && status !== 'succeeded'">
                                        <x-icon name="heroicon-o-x-circle" class="w-4 h-4 text-danger" />
                                    </template>
                                    <span class="text-sm font-medium text-ink" x-text="label"></span>
                                </div>
                                <template x-if="error">
                                    <p class="text-xs text-danger" x-text="error"></p>
                                </template>
                                <template x-if="!finished">
                                    <button
                                        type="button"
                                        wire:click="cancelBackgroundChat"
                                        class="text-xs text-muted hover:text-danger transition-colors"
                                    >
                                        {{ __('Cancel') }}
                                    </button>
                                </template>
                            </div>
                        </div>
                    @endif

                    {{-- Jump to latest floating button --}}
                    <button
                        x-show="!followTail"
                        x-cloak
                        @click="followTail = true; $refs.agentScroll.scrollTop = $refs.agentScroll.scrollHeight"
                        class="sticky bottom-2 left-1/2 -translate-x-1/2 z-10 inline-flex items-center gap-1 rounded-full bg-surface-card border border-border-default shadow-lg px-3 py-1.5 text-xs text-muted hover:text-ink transition-colors"
                        aria-label="{{ __('Jump to latest') }}"
                    >
                        <x-icon name="heroicon-o-arrow-down" class="w-3.5 h-3.5" />
                        {{ __('Jump to latest') }}
                    </button>
                </div>

                {{-- Composer --}}
                <div class="border-t border-border-default px-4 py-3 space-y-2">
                    {{-- Model picker + session usage --}}
                    <div class="flex items-center justify-between gap-2">
                        @if ($canSelectModel && count($availableModels) > 0)
                            <div class="flex items-center gap-1">
                                <x-icon name="heroicon-o-cpu-chip" class="w-3 h-3 text-muted shrink-0" />
                                <x-ai.model-selector
                                    :models="$availableModels"
                                    wire:model.live="selectedModel"
                                    class="max-w-xs !py-0.5 !text-[11px]"
                                    aria-label="{{ __('AI model') }}"
                                    :empty-label="$currentModel"
                                />
                            </div>
                        @else
                            <div class="inline-flex items-center gap-1 text-[11px] text-muted">
                                <x-icon name="heroicon-o-cpu-chip" class="w-3 h-3" />
                                <span class="truncate max-w-[12rem]">{{ $currentModel }}</span>
                            </div>
                        @endif

                        @if ($sessionUsage && ($sessionUsage['total_prompt_tokens'] > 0 || $sessionUsage['total_completion_tokens'] > 0))
                            <div class="inline-flex items-center gap-1 text-[10px] text-muted tabular-nums" title="{{ __('Session tokens: :prompt prompt + :completion completion across :runs run(s)', ['prompt' => number_format($sessionUsage['total_prompt_tokens']), 'completion' => number_format($sessionUsage['total_completion_tokens']), 'runs' => $sessionUsage['run_count']]) }}">
                                <x-icon name="heroicon-o-chart-bar" class="w-3 h-3" />
                                <span>{{ number_format($sessionUsage['total_prompt_tokens'] + $sessionUsage['total_completion_tokens']) }} {{ __('tokens') }}</span>
                            </div>
                        @endif
                    </div>

                    <form
                        wire:submit="sendMessage"
                        x-data="agentChatComposer()"
                        x-on:submit="onSubmit($refs.agentComposer, $refs.agentScroll)"
                        class="space-y-2"
                    >
                        <x-ai.chat-composer-fields
                            :can-attach-files="$canAttachFiles"
                            :attachments="$this->attachments"
                            attachments-model="attachments"
                            remove-attachment-action="removeAttachment"
                            message-model="messageInput"
                            placeholder="{{ __('Message :name...', ['name' => $agentIdentity['name']]) }}"
                            composer-ref="agentComposer"
                            pending-expression="!!pendingMessage"
                        />
                    </form>
                </div>
            </section>
        </div>
    @endif
</div>

@script
<script>
    Alpine.data('agentChatComposer', () => ({
        async onSubmit(textarea, scrollContainer) {
            const value = textarea.value.trim();
            const hasAttachments = document.querySelectorAll('[wire\\:key^="att-"]').length > 0;
            if (!value && !hasAttachments) return;

            this.pendingMessage = value || '📎';
            textarea.value = '';
            textarea.style.height = 'auto';
            this.$nextTick(() => {
                if (scrollContainer) scrollContainer.scrollTop = scrollContainer.scrollHeight;
            });

            // Capture the real page URL before the Livewire update fires
            await this.$wire.set('pageUrl', window.location.href);

            // Try streaming first
            try {
                const result = await this.$wire.prepareStreamingRun();

                if (result && result.url) {
                    this.startStream(result.url, scrollContainer);
                    return;
                }
            } catch (e) {
                // Streaming unavailable — fall through to sync
            }

            // Fallback: synchronous Livewire sendMessage
            // (also handles orchestration commands that returned null from prepareStreamingRun)
        },

        startStream(url, scrollContainer) {
            if (this._eventSource) {
                this._eventSource.close();
            }

            this.streamEntries = [];
            this.followTail = true;
            this._currentRunId = null;
            const source = new EventSource(url);
            this._eventSource = source;

            // Track which tool_call entry index corresponds to which tool_call_index
            const toolCallMap = {};

            source.addEventListener('status', (e) => {
                try {
                    const data = JSON.parse(e.data);

                    if (data.run_id) {
                        this._currentRunId = data.run_id;
                    }

                    if (data.phase === 'thinking') {
                        // Add thinking entry only once
                        const existingThinking = this.streamEntries.find(entry => entry.type === 'thinking');
                        if (!existingThinking) {
                            this.streamEntries.push({
                                type: 'thinking',
                                active: true,
                            });
                        }
                    } else if (data.phase === 'tool_started') {
                        // Deactivate thinking
                        const thinking = this.streamEntries.find(entry => entry.type === 'thinking');
                        if (thinking) thinking.active = false;

                        const idx = this.streamEntries.length;
                        toolCallMap[data.tool_call_index ?? idx] = idx;

                        this.streamEntries.push({
                            type: 'tool_call',
                            tool: data.tool || '',
                            argsSummary: data.args_summary || '',
                            status: 'running',
                        });
                    } else if (data.phase === 'tool_finished') {
                        const callIdx = toolCallMap[data.tool_call_index ?? -1];
                        if (callIdx !== undefined && this.streamEntries[callIdx]) {
                            const entry = this.streamEntries[callIdx];
                            entry.status = data.status || 'success';
                            entry.durationMs = data.duration_ms;
                            entry.resultPreview = data.result_preview || '';
                            entry.resultLength = data.result_length || 0;
                            entry.errorPayload = data.error_payload || null;
                        }
                    } else if (data.phase === 'hook_action') {
                        this.streamEntries.push({
                            type: 'hook_action',
                            stage: data.stage || '',
                            action: 'tools_removed',
                            toolsRemoved: data.tools_removed || [],
                        });
                    } else if (data.phase === 'tool_denied') {
                        this.streamEntries.push({
                            type: 'hook_action',
                            stage: 'pre_tool_use',
                            action: 'tool_denied',
                            tool: data.tool || '',
                            reason: data.reason || '',
                            source: data.source || 'hook',
                        });
                    }
                } catch {}
                this.scrollToBottom(scrollContainer);
            });

            source.addEventListener('delta', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    if (data.text) {
                        // Deactivate thinking
                        const thinking = this.streamEntries.find(entry => entry.type === 'thinking');
                        if (thinking) thinking.active = false;

                        const last = this.streamEntries[this.streamEntries.length - 1];
                        if (last && last.type === 'assistant_streaming') {
                            last.content += data.text;
                        } else {
                            this.streamEntries.push({
                                type: 'assistant_streaming',
                                content: data.text,
                            });
                        }
                    }
                } catch {}
                this.scrollToBottom(scrollContainer);
            });

            source.addEventListener('done', (e) => {
                source.close();
                this._eventSource = null;
                this.$wire.finalizeStreamingRun();
            });

            source.addEventListener('error', (e) => {
                // Check if this is an SSE error event from our server
                if (e.data) {
                    try {
                        const data = JSON.parse(e.data);
                        if (data.message) {
                            this.streamEntries.push({
                                type: 'error',
                                message: data.message,
                            });
                        }
                    } catch {}
                }

                source.close();
                this._eventSource = null;
                this.$wire.finalizeStreamingRun();
            });

            // EventSource built-in error (connection lost)
            source.onerror = () => {
                if (source.readyState === EventSource.CLOSED) return;
                source.close();
                this._eventSource = null;

                // If no content was streamed, fall back to sync
                const hasContent = this.streamEntries.some(e => e.type === 'assistant_streaming');
                if (!hasContent) {
                    this.$wire.sendMessage();
                } else {
                    this.$wire.finalizeStreamingRun();
                }
            };
        },

        scrollToBottom(container) {
            if (this.followTail) {
                this.$nextTick(() => {
                    if (container) container.scrollTop = container.scrollHeight;
                });
            }
        },

    }));
</script>
@endscript
