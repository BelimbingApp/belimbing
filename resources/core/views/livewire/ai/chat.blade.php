<?php
/** @var \App\Modules\Core\AI\Livewire\Chat $this */
?>
<div
    class="h-full flex flex-col"
    @if ($hasPendingDelegations || $hasActiveTurns)
        wire:poll.2s
    @endif
    x-data="{
        sessionsOpen: false,
        sessionWidth: parseInt(localStorage.getItem('agent-chat-session-width')) || 224,
        _draftKey: 'blb-lara-draft-{{ auth()->id() }}',
        _sessionDragging: false,
        activeTurnSummaries: @js($activeTurnsBySession ?? []),
        replayUrlTemplate: @js(route('ai.chat.turn.events', ['runId' => '__TURN__'])),
        terminalTurnStatuses: ['succeeded', 'failed', 'cancelled', 'timed_out'],
        phaseLabels: @js($phaseLabels),
        stoppingLabel: @js(__('Stopping… waiting for stream to stop')),
        _summaryPollTimer: null,
        SESSION_MIN: 160,
        SESSION_MAX: 320,

        formatElapsedFrom(isoTimestamp) {
            if (!isoTimestamp) {
                return '0s';
            }

            const startedAt = new Date(isoTimestamp).getTime();
            if (Number.isNaN(startedAt)) {
                return '0s';
            }

            const elapsedSeconds = Math.max(0, Math.floor((Date.now() - startedAt) / 1000));

            if (elapsedSeconds < 60) {
                return elapsedSeconds + 's';
            }

            return Math.floor(elapsedSeconds / 60) + 'm ' + (elapsedSeconds % 60) + 's';
        },

        replayUrlFor(runId, afterSeq = 0) {
            return this.replayUrlTemplate.replace('__TURN__', runId) + '?after_seq=' + afterSeq;
        },

        labelForPhase(phase, fallback = null) {
            if (phase && this.phaseLabels[phase]) {
                return this.phaseLabels[phase];
            }

            return fallback;
        },

        isTerminalSummary(summary) {
            return !!summary?.status && this.terminalTurnStatuses.includes(summary.status);
        },

        isActiveSummary(sessionId) {
            const summary = (this.activeTurnSummaries ?? {})[sessionId] || null;

            return !!summary && !this.isTerminalSummary(summary);
        },

        pruneTerminalSummaries() {
            Object.entries(this.activeTurnSummaries ?? {}).forEach(([sessionId, summary]) => {
                if (this.isTerminalSummary(summary)) {
                    this.clearSummary(sessionId, summary?.runId || null);
                }
            });
        },

        syncSummary(sessionId, patch) {
            const summaries = this.activeTurnSummaries ?? {};
            const current = summaries[sessionId] || {};
            const merged = {
                ...current,
                ...patch,
            };

            if (this.isTerminalSummary(merged)) {
                this.clearSummary(sessionId, merged.runId || null);
                return;
            }

            this.activeTurnSummaries = {
                ...summaries,
                [sessionId]: merged,
            };
        },

        clearSummary(sessionId, runId = null) {
            const current = (this.activeTurnSummaries ?? {})[sessionId] || null;
            if (!current) {
                return;
            }

            if (runId && current.runId && current.runId !== runId) {
                return;
            }

            const next = { ...(this.activeTurnSummaries ?? {}) };
            delete next[sessionId];
            this.activeTurnSummaries = next;

            if (Object.keys(this.activeTurnSummaries ?? {}).length === 0) {
                this.stopSummaryPolling();
            }
        },

        stopSummaryPolling() {
            if (this._summaryPollTimer) {
                clearInterval(this._summaryPollTimer);
                this._summaryPollTimer = null;
            }
        },

        async refreshSummary(sessionId, summary) {
            if (!summary?.runId) {
                this.clearSummary(sessionId);
                return;
            }

            const afterSeq = summary.lastSeq || 0;
            const response = await fetch(this.replayUrlFor(summary.runId, afterSeq), {
                credentials: 'same-origin',
            });

            if (!response.ok) {
                this.clearSummary(sessionId, summary.runId);
                return;
            }

            const payload = await response.json();
            const latestSeq = (payload.events || []).reduce((max, event) => Math.max(max, Number.parseInt(event?.seq ?? 0, 10) || 0), afterSeq);

            if (this.terminalTurnStatuses.includes(payload.status)) {
                this.clearSummary(sessionId, summary.runId);
                return;
            }

            this.syncSummary(sessionId, {
                runId: summary.runId,
                session_id: sessionId,
                status: payload.status,
                phase: payload.current_phase || summary.phase || null,
                label: (payload.cancel_requested_at || summary.stop_requested)
                    ? this.stoppingLabel
                    : (payload.current_label
                        || this.labelForPhase(payload.current_phase, null)
                        || summary.label
                        || null),
                started_at: payload.started_at || summary.started_at || null,
                created_at: payload.created_at || summary.created_at || null,
                timer_anchor_at: payload.started_at || payload.created_at || summary.timer_anchor_at || null,
                cancel_requested_at: payload.cancel_requested_at || summary.cancel_requested_at || null,
                stop_requested: !!(payload.cancel_requested_at || summary.stop_requested),
                lastSeq: latestSeq,
            });
        },

        startSummaryPolling() {
            this.pruneTerminalSummaries();

            if (this._summaryPollTimer || Object.keys(this.activeTurnSummaries ?? {}).length === 0) {
                return;
            }

            const poll = () => {
                Object.entries(this.activeTurnSummaries ?? {}).forEach(([sessionId, summary]) => {
                    this.refreshSummary(sessionId, summary).catch(() => {
                        this.clearSummary(sessionId, summary?.runId || null);
                    });
                });
            };

            this._summaryPollTimer = setInterval(poll, 2000);
            poll();
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
        const savedSession = localStorage.getItem('blb-lara-session');
        if (savedSession && !$wire.selectedSessionId) {
            $wire.set('selectedSessionId', savedSession);
        }
        $nextTick(() => $refs.agentComposer?.focus());
        window.__laraPageUrlCleanup?.();
        const syncPageUrl = () => $wire.set('pageUrl', window.location.href);
        syncPageUrl();
        document.addEventListener('livewire:navigated', syncPageUrl);
        window.__laraPageUrlCleanup = () => {
            document.removeEventListener('livewire:navigated', syncPageUrl);
            window.__laraPageUrlCleanup = null;
        };
        $watch('$wire.selectedSessionId', v => {
            if (v) {
                localStorage.setItem('blb-lara-session', v);
            } else {
                localStorage.removeItem('blb-lara-session');
            }
        });

        const savedDraft = localStorage.getItem($data._draftKey);
        if (savedDraft && !$wire.messageInput) {
            $wire.set('messageInput', savedDraft);
            $nextTick(() => {
                if ($refs.agentComposer) {
                    $refs.agentComposer.value = savedDraft;
                    window.sharedChatComposerAutoResize($refs.agentComposer);
                }
            });
        }

        $watch('$wire.messageInput', v => {
            if (v && v.trim()) {
                localStorage.setItem($data._draftKey, v);
            } else {
                localStorage.removeItem($data._draftKey);
            }
        });
        $data.pruneTerminalSummaries();
        if (Object.keys($data.activeTurnSummaries ?? {}).length > 0) {
            $data.startSummaryPolling();
        }
    "
    @agent-chat-response-ready.window="if ($event.detail?.sessionId) clearSummary($event.detail.sessionId, $event.detail?.runId || null)"
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
            @if ($activeTurnCount > 0)
                <span
                    x-show="!sessionsOpen"
                    x-cloak
                    class="inline-flex min-w-4 h-4 items-center justify-center rounded-full bg-accent/15 px-1 text-[10px] tabular-nums text-accent"
                    title="{{ trans_choice(':count active session|:count active sessions', $activeTurnCount, ['count' => $activeTurnCount]) }}"
                >
                    {{ $activeTurnCount }}
                </span>
            @endif
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
                                            wire:blur="saveTitle"
                                            x-init="$nextTick(() => $el.focus())"
                                            aria-label="{{ __('Session title') }}"
                                            class="flex-1 min-w-0 text-[11px] font-medium bg-surface-default border border-border-default rounded px-1.5 py-0.5 text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                                            placeholder="{{ __('Session title') }}"
                                        />
                                        <button
                                            type="button"
                                            wire:click="generateSessionTitle('{{ $session->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="generateSessionTitle('{{ $session->id }}')"
                                            class="text-muted hover:text-accent disabled:opacity-60 disabled:cursor-wait transition-colors p-0.5 shrink-0"
                                            title="{{ __('Suggest a title') }}"
                                            aria-label="{{ __('Suggest a title') }}"
                                        >
                                            <span wire:loading.remove wire:target="generateSessionTitle('{{ $session->id }}')">
                                                <x-icon name="heroicon-o-sparkles" class="w-3.5 h-3.5" />
                                            </span>
                                            <span wire:loading wire:target="generateSessionTitle('{{ $session->id }}')">
                                                <x-icon name="heroicon-o-arrow-path" class="w-3.5 h-3.5 animate-spin" />
                                            </span>
                                        </button>
                                    </div>
                                    <output class="block text-[10px] leading-snug" aria-live="polite">
                                        <span
                                            wire:loading
                                            wire:target="generateSessionTitle('{{ $session->id }}')"
                                            class="text-muted"
                                        >
                                            {{ __('Suggesting title…') }}
                                        </span>
                                        @if ($titleSuggestionSessionId === $session->id && $titleSuggestionMessage !== null)
                                            <span @class([
                                                'text-accent' => $titleSuggestionTone === 'success',
                                                'text-muted' => $titleSuggestionTone === 'info',
                                                'text-warning' => $titleSuggestionTone === 'warning',
                                                'text-danger' => $titleSuggestionTone === 'error',
                                            ])>
                                                {{ $titleSuggestionMessage }}
                                            </span>
                                        @endif
                                    </output>
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
                                            class="w-full text-left text-[11px] leading-snug line-clamp-2 font-medium hover:text-ink"
                                            title="{{ __('Edit title') }}"
                                        >
                                            {{ $session->title ?? __('Untitled') }}<span class="sr-only">, {{ __('edit title') }}</span>
                                        </button>
                                        <div class="text-[11px] text-muted tabular-nums">{{ $session->lastActivityAt->format('M j, H:i') }}</div>
                                        @if ($canAccessControlPlane && isset($sessionTurnTargets[$session->id]))
                                            <div class="mt-0.5">
                                                <a
                                                    href="{{ route('admin.ai.control-plane', ['tab' => 'inspector', 'runId' => $sessionTurnTargets[$session->id]['run_id']]) }}"
                                                    wire:navigate
                                                    class="text-[10px] text-accent hover:underline"
                                                >
                                                    {{ $sessionTurnTargets[$session->id]['is_active'] ? __('Current Turn') : __('Last Turn') }}:
                                                    <span class="font-mono">{{ \Illuminate\Support\Str::limit($sessionTurnTargets[$session->id]['run_id'], 14, '...') }}</span>
                                                </a>
                                            </div>
                                        @endif
                                        <div
                                            x-show="isActiveSummary('{{ $session->id }}')"
                                            x-cloak
                                            class="mt-0.5 flex items-center gap-1.5 min-w-0 text-[10px] text-accent"
                                        >
                                            <span class="w-1.5 h-1.5 rounded-full bg-accent animate-pulse shrink-0"></span>
                                            <span
                                                class="truncate"
                                                x-text="activeTurnSummaries['{{ $session->id }}']?.label || '{{ __('Active') }}'"
                                            ></span>
                                            <span
                                                class="tabular-nums text-muted/80 shrink-0"
                                                x-text="formatElapsedFrom(activeTurnSummaries['{{ $session->id }}']?.timer_anchor_at || null)"
                                            ></span>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        x-show="isActiveSummary('{{ $session->id }}')"
                                        x-cloak
                                        x-on:click.stop="
                                            const summary = activeTurnSummaries['{{ $session->id }}'];
                                            if (!summary?.runId || summary.stop_requested) return;
                                            syncSummary('{{ $session->id }}', {
                                                label: stoppingLabel,
                                                stop_requested: true,
                                                cancel_requested_at: new Date().toISOString(),
                                            });
                                            $wire.cancelActiveTurn(summary.runId);
                                        "
                                        :disabled="!!activeTurnSummaries['{{ $session->id }}']?.stop_requested"
                                        class="text-muted hover:text-ink disabled:opacity-60 disabled:cursor-not-allowed p-1 shrink-0"
                                        :title="activeTurnSummaries['{{ $session->id }}']?.stop_requested ? '{{ __('Waiting for stream to stop') }}' : '{{ __('Stop active turn') }}'"
                                        :aria-label="activeTurnSummaries['{{ $session->id }}']?.stop_requested ? '{{ __('Waiting for stream to stop') }}' : '{{ __('Stop active turn') }}'"
                                    >
                                        <x-icon name="heroicon-o-stop" class="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        x-show="!isActiveSummary('{{ $session->id }}')"
                                        x-cloak
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
                x-data="agentChatStream({
                    startingLabel: @js(__('Starting…')),
                    stoppingLabel: @js(__('Stopping… waiting for stream to stop')),
                    waitingForWorkerLabel: @js(__('Waiting for worker…')),
                    runFailedMessage: @js(__('Turn failed')),
                    connectionLostMessage: @js(__('Connection lost. Please try again.')),
                    reconnectingLabel: @js(__('Connection interrupted. Reconnecting…')),
                    reasoningLabel: @js(__('Reasoning…')),
                    writingLabel: @js(__('Writing…')),
                    runningToolLabelTemplate: @js(__('Running :tool…')),
                    replayUrlTemplate: @js(route('ai.chat.turn.events', ['runId' => '__TURN__'])),
                })"
                x-effect="window.dispatchEvent(new CustomEvent(isBusy ? 'agent-chat-busy' : 'agent-chat-idle'))"
                x-on:agent-chat-response-ready.window="onServerTurnReady($event.detail || {})"
                x-on:agent-chat-session-selected.window="onSessionSelected($event.detail || {}, $refs.agentScroll)"
            >
                <div
                    class="flex-1 min-w-0 min-h-0 overflow-y-auto px-4 py-3 space-y-3 relative"
                    x-ref="agentScroll"
                    x-init="
                        $nextTick(() => $el.scrollTop = $el.scrollHeight);
                        const selectedTurn = @js($selectedSessionActiveTurn);
                        if (selectedTurn?.runId) {
                            $nextTick(() => resumeKnownTurn(selectedTurn, $el));
                        }
                    "
                    @scroll.throttle.100ms="
                        const el = $refs.agentScroll;
                        followTail = (el.scrollHeight - el.scrollTop - el.clientHeight) < 50;
                    "
                    x-effect="if (followTail) $nextTick(() => $refs.agentScroll.scrollTop = $refs.agentScroll.scrollHeight)"
                >
                    @forelse($messages as $index => $message)
                        @php
                            $messageProvider = $message->getMetaString('provider_name') ?? $message->getMetaString('llm.provider');
                            $messageModel = $message->getMetaString('model') ?? $message->getMetaString('llm.model');
                            $messageTokens = $message->getMeta('tokens');
                            $messageLatencyMs = $message->getMetaInt('latency_ms');
                            $messageAiActiveDurationMs = $message->getMetaInt('ai_active_duration_ms');
                            $messageTimeoutSeconds = $message->getMetaInt('timeout_seconds');
                            $messageRetryAttempts = $message->getMetaInt('retry_attempts');
                            $messageErrorType = $message->getMetaString('error_type');
                            $messageErrorMessage = $message->getMetaString('error');
                            $messageRunStatus = $message->getMetaString('status');
                            $messageStopNote = $message->getMetaString('stop_note');
                            $messageTool = $message->getMetaString('tool', '');
                            $messageArgsSummary = $message->getMetaString('args_summary', '{}');
                            $messageDisplaySummary = $message->getMetaString('display_summary', '');
                            $messageStatus = $message->getMetaString('status', 'success');
                            $messageDurationMs = $message->getMetaInt('duration_ms');
                            $messageResultPreview = $message->getMetaString('result_preview', '');
                            $messageResultLength = $message->getMetaInt('result_length', 0);
                            $messageErrorPayload = $message->getMeta('error_payload');
                            $messageStage = $message->getMetaString('stage', 'unknown');
                            $messageAction = $message->getMetaString('action', 'unknown');
                            $messageToolsRemoved = $message->getMetaArray('tools_removed');
                            $messageReason = $message->getMetaString('reason');
                            $messageSource = $message->getMetaString('source');
                            $messageType = $message->getMetaString('message_type');
                            $messageOrchestrationStatus = $message->getMeta('orchestration.status');
                        @endphp

                        @if ($message->type === 'thinking')
                            <x-ai.activity.thinking :timestamp="$message->timestamp" :active="false" :content="$message->content" />
                        @elseif ($message->type === 'tool_use')
                            <x-ai.activity.tool-use
                                :tool="$messageTool"
                                :args-summary="$messageArgsSummary"
                                :display-summary="$messageDisplaySummary"
                                :status="$messageStatus"
                                :duration-ms="$messageDurationMs"
                                :result-preview="$messageResultPreview"
                                :result-length="$messageResultLength"
                                :error-payload="$messageErrorPayload"
                            />
                        @elseif ($message->type === 'hook_action')
                            <x-ai.activity.hook-action
                                :stage="$messageStage"
                                :action="$messageAction"
                                :tool="$messageTool !== '' ? $messageTool : null"
                                :tools-removed="$messageToolsRemoved"
                                :reason="$messageReason"
                                :source="$messageSource"
                                :timestamp="$message->timestamp"
                            />
                        @elseif ($message->role === 'user')
                            <x-ai.activity.user-message
                                :content="$message->content"
                                :timestamp="$message->timestamp"
                                :meta="$message->meta"
                            />
                        @elseif ($message->role === 'assistant' && $messageType === 'error')
                            <x-ai.activity.error
                                :message="$message->content"
                                :error-type="$messageErrorType"
                                :error-message="$messageErrorMessage"
                                :timestamp="$message->timestamp"
                                :run-id="$message->runId"
                                :provider="$messageProvider"
                                :model="$messageModel"
                                :markdown="$markdown"
                                :latency-ms="$messageLatencyMs"
                            />
                        @elseif ($message->role === 'assistant' && $messageOrchestrationStatus !== null)
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
                                :ai-active-duration-ms="$messageAiActiveDurationMs"
                                :timeout-seconds="$messageTimeoutSeconds"
                                :retry-attempts="$messageRetryAttempts"
                                :run-status="$messageRunStatus"
                                :stop-note="$messageStopNote"
                            />
                        @endif
                    @empty
                        <div x-show="!pendingMessage" class="h-full flex flex-col items-center justify-center gap-4">
                            <p class="text-sm text-muted">{{ __('Send a message to start chatting with :name.', ['name' => $agentIdentity['name']]) }}</p>
                            @if (count($quickPrompts) > 0)
                                <div class="flex flex-wrap justify-center gap-2 max-w-sm">
                                    @foreach ($quickPrompts as $prompt)
                                        <button
                                            type="button"
                                            x-on:click="$dispatch('open-agent-chat', { prompt: '{{ str_replace("'", "\\'", $prompt['prompt']) }}' })"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-border-default bg-surface-card text-xs text-muted hover:text-ink hover:border-accent/40 hover:bg-surface-subtle transition-all duration-200"
                                        >
                                            <x-icon :name="$prompt['icon']" class="w-3.5 h-3.5" />
                                            <span>{{ $prompt['label'] }}</span>
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
                    {{-- Collapsed tools summary (shows when older tools are collapsed) --}}
                    <template x-if="false"></template>

                    <template x-for="(entry, idx) in streamEntries" :key="idx">
                        <div x-show="
                            entry.type !== 'tool_use'
                            || !toolsCollapsed
                            || entry.status === 'running'
                        ">
                            {{-- Thinking --}}
                            <template x-if="entry.type === 'thinking'">
                                <div class="flex gap-2 py-1">
                                    <div class="flex flex-col gap-0.5 min-w-0 max-w-full">
                                        <div class="flex items-center gap-1.5 text-xs text-muted">
                                            <template x-if="entry.active">
                                                <span class="w-2 h-2 bg-accent rounded-full animate-pulse"></span>
                                            </template>
                                            <span
                                                x-text="
                                                    entry.active
                                                        ? (entry.thinkingContent && entry.thinkingContent.trim()
                                                            ? @js(__('Reasoning…'))
                                                            : (phaseLabels.awaiting_llm || @js(__('Awaiting model response…'))))
                                                        : @js(__('Reasoning…'))
                                                "
                                            ></span>
                                        </div>
                                        <template x-if="entry.description">
                                            <div class="text-[11px] text-muted/70 italic" x-text="entry.description"></div>
                                        </template>
                                        <template x-if="entry.thinkingContent">
                                            <div class="text-xs text-muted/80 whitespace-pre-wrap break-words max-h-64 overflow-y-auto border-l-2 border-accent/20 pl-2 mt-1" x-text="entry.thinkingContent"></div>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            {{-- Tool use --}}
                            <template x-if="entry.type === 'tool_use'">
                                <div class="py-1">
                                    <div class="min-w-0">
                                        <div class="rounded-lg border border-border-default bg-surface-card px-2.5 py-1.5 text-xs">
                                            <div class="flex items-start gap-2">
                                                <span class="min-w-0 flex-1 font-medium text-ink break-words" x-text="entry.tool"></span>
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
                                                </span>
                                            </div>
                                            <template x-if="entry.displaySummary">
                                                <div>
                                                    <div class="mt-1 text-muted break-words text-[11px]" x-text="entry.displaySummary"></div>
                                                    <template x-if="entry.argsSummary && entry.argsSummary !== '{}'">
                                                        <details class="mt-0.5">
                                                            <summary class="cursor-pointer select-none text-[10px] text-muted/70 hover:text-muted">{{ __('Raw arguments') }}</summary>
                                                            <div class="mt-1 text-muted whitespace-pre-wrap break-all font-mono text-[11px]" x-text="entry.argsSummary"></div>
                                                        </details>
                                                    </template>
                                                </div>
                                            </template>
                                            <template x-if="!entry.displaySummary && entry.argsSummary">
                                                <div
                                                    class="mt-1 text-muted whitespace-pre-wrap break-all"
                                                    :class="entry.tool === 'bash' ? 'font-mono text-[11px]' : ''"
                                                    x-text="entry.argsSummary"
                                                ></div>
                                            </template>
                                            {{-- Live stdout while tool is running --}}
                                            <template x-if="entry.stdoutBuffer && entry.status === 'running'">
                                                <div class="mt-1.5 max-h-40 overflow-y-auto rounded bg-surface-inset px-2 py-1.5 font-mono text-[11px] text-muted whitespace-pre-wrap break-all border border-border-subtle">
                                                    <span x-text="entry.stdoutBuffer"></span>
                                                </div>
                                            </template>
                                            <div class="mt-2 border-t border-border-default pt-2">
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

                {{-- Sticky turn status bar (coding-agent console) --}}
                <div
                    x-show="isBusy"
                    x-cloak
                    aria-live="polite"
                    class="border-t border-border-default bg-surface-subtle/60 px-4 py-1.5 flex items-center gap-3 text-xs shrink-0"
                >
                    <span class="w-2 h-2 bg-accent rounded-full animate-pulse shrink-0"></span>
                    <span class="text-muted truncate" x-text="runLabel || '{{ __('Processing…') }}'"></span>
                    <span class="tabular-nums text-muted/70 shrink-0" x-text="
                        elapsedSeconds < 60
                            ? elapsedSeconds + 's'
                            : Math.floor(elapsedSeconds / 60) + 'm ' + (elapsedSeconds % 60) + 's'
                    "></span>
                    @if ($canAccessControlPlane && $selectedSessionTurnTarget)
                        <a
                            href="{{ route('admin.ai.control-plane', ['tab' => 'inspector', 'runId' => $selectedSessionTurnTarget['run_id']]) }}"
                            wire:navigate
                            class="ml-auto inline-flex items-center gap-1 rounded-full border border-border-default bg-surface-card px-2 py-0.5 text-[11px] text-accent hover:border-accent/40 hover:bg-surface-subtle transition-colors shrink-0"
                        >
                            <span>{{ __('Control Panel') }}</span>
                            <x-icon name="heroicon-o-arrow-top-right-on-square" class="h-3 w-3" />
                        </a>
                    @endif
                    <button
                        type="button"
                        @click="stopStreaming()"
                        :disabled="stopRequested"
                        class="@if (! ($canAccessControlPlane && $selectedSessionTurnTarget)) ml-auto @endif inline-flex items-center gap-1 px-2 py-0.5 rounded-full border border-border-default bg-surface-card text-muted hover:text-ink hover:border-accent/40 disabled:opacity-60 disabled:cursor-not-allowed transition-colors shrink-0"
                    >
                        <x-icon name="heroicon-o-stop" class="w-3 h-3" />
                        <span x-text="stopRequested ? @js(__('Stopping…')) : @js(__('Stop'))"></span>
                    </button>
                </div>

                {{-- Composer --}}
                <div class="border-t border-border-default px-4 py-3 space-y-2">

                    {{-- Model picker + session usage --}}
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            @if ($canSelectModel && count($availableModels) > 0)
                                <div class="flex items-center gap-1 min-w-0">
                                    <x-ai.model-selector
                                        :models="$availableModels"
                                        wire:model.live="selectedModel"
                                        class="max-w-xs !py-0.5 !text-[11px]"
                                        aria-label="{{ __('AI model') }}"
                                    />
                                    @if (count($availableEfforts) > 0)
                                        <label class="sr-only" for="ai-effort-selector">{{ __('Reasoning effort') }}</label>
                                        <x-ui.select
                                            id="ai-effort-selector"
                                            :block="false"
                                            wire:model.live="selectedEffort"
                                            title="{{ __('Reasoning effort') }}"
                                            class="shrink-0 text-xs"
                                        >
                                            <option value="">{{ __('Effort: auto') }}</option>
                                            @foreach ($availableEfforts as $effort)
                                                <option value="{{ $effort['value'] }}">{{ $effort['label'] }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    @endif
                                </div>
                            @else
                                <div class="inline-flex items-center gap-1 text-[11px] text-muted min-w-0">
                                    <span class="truncate max-w-[12rem]">{{ $currentModel }}</span>
                                </div>
                            @endif

                            <x-ui.button
                                variant="ghost"
                                size="sm"
                                wire:click="createSession"
                                class="shrink-0 !px-2"
                                title="{{ __('New session') }}"
                                aria-label="{{ __('New session') }}"
                            >
                                <x-icon name="heroicon-o-plus" class="w-3 h-3" />
                            </x-ui.button>
                        </div>

                        @if ($sessionUsage && ($sessionUsage['total_prompt_tokens'] > 0 || $sessionUsage['total_completion_tokens'] > 0))
                            <div class="inline-flex items-center gap-1 text-[10px] text-muted tabular-nums shrink-0" title="{{ __('Session tokens: :prompt prompt + :completion completion across :runs run(s)', ['prompt' => number_format($sessionUsage['total_prompt_tokens']), 'completion' => number_format($sessionUsage['total_completion_tokens']), 'runs' => $sessionUsage['run_count']]) }}">
                                <x-icon name="heroicon-o-chart-bar" class="w-3 h-3" />
                                <span>{{ number_format($sessionUsage['total_prompt_tokens'] + $sessionUsage['total_completion_tokens']) }} {{ __('tokens') }}</span>
                            </div>
                        @endif
                    </div>

                    <form
                        x-data="agentChatComposer()"
                        x-on:submit.prevent="onSubmit($refs.agentComposer, $refs.agentScroll)"
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
    window.blbCollectLaraActivePageSnapshot = () => {
        const isInLara = (element) => element.closest?.('#lara-chat-instance, #lara-chat-home');
        const text = (value, max = 240) => (value || '').replace(/\s+/g, ' ').trim().slice(0, max);
        const labelFor = (element) => {
            const id = element.getAttribute('id');
            const label = id ? document.querySelector(`label[for="${CSS.escape(id)}"]`) : null;

            return text(
                element.getAttribute('name')
                    || element.getAttribute('aria-label')
                    || label?.textContent
                    || element.getAttribute('placeholder')
                    || id
                    || element.getAttribute('wire:model')
                    || element.getAttribute('wire:model.live')
                    || element.tagName.toLowerCase()
            );
        };
        const isSensitive = (element, name) => {
            const haystack = [
                element.getAttribute('type'),
                element.getAttribute('autocomplete'),
                name,
                element.getAttribute('id'),
                element.getAttribute('aria-label'),
                element.getAttribute('placeholder'),
            ].join(' ').toLowerCase();

            return /password|secret|token|api[_ -]?key|credential|private|cert|client[_ -]?id/.test(haystack);
        };
        const valueFor = (element) => {
            if (element instanceof HTMLInputElement && ['checkbox', 'radio'].includes(element.type)) {
                return element.checked;
            }

            if (element instanceof HTMLSelectElement) {
                return Array.from(element.selectedOptions).map(option => text(option.textContent || option.value, 120)).join(', ');
            }

            return text(element.value ?? element.textContent ?? '', 500);
        };
        const fieldsFor = (container) => Array.from(container.querySelectorAll('input, select, textarea'))
            .filter(element => !isInLara(element) && !element.disabled && element.type !== 'hidden')
            .slice(0, 80)
            .map((element) => {
                const name = labelFor(element);
                const masked = isSensitive(element, name);

                return {
                    name,
                    type: element.getAttribute('type') || element.tagName.toLowerCase(),
                    value: masked ? null : valueFor(element),
                    masked,
                };
            })
            .filter(field => field.name);
        const forms = Array.from(document.querySelectorAll('form, [wire\\:submit], [data-lara-form]'))
            .filter(element => !isInLara(element))
            .slice(0, 12)
            .map((element, index) => ({
                id: text(element.getAttribute('id') || element.getAttribute('name') || element.getAttribute('aria-label') || `form-${index + 1}`, 80),
                fields: fieldsFor(element),
            }))
            .filter(form => form.fields.length > 0);
        const looseFields = fieldsFor(document.body);

        if (forms.length === 0 && looseFields.length > 0) {
            forms.push({ id: 'page-fields', fields: looseFields });
        }

        return {
            forms,
            tables: Array.from(document.querySelectorAll('table'))
                .filter(element => !isInLara(element))
                .slice(0, 8)
                .map((table, index) => ({
                    id: text(table.getAttribute('id') || table.getAttribute('aria-label') || `table-${index + 1}`, 80),
                    columns: Array.from(table.querySelectorAll('thead th, thead td')).slice(0, 24).map(cell => text(cell.textContent, 80)).filter(Boolean),
                    total_rows: table.querySelectorAll('tbody tr').length,
                })),
            modals: Array.from(document.querySelectorAll('[role="dialog"], [aria-modal="true"]'))
                .filter(element => !isInLara(element))
                .slice(0, 8)
                .map((element, index) => ({
                    id: text(element.getAttribute('id') || element.getAttribute('aria-label') || `modal-${index + 1}`, 80),
                    title: text(element.querySelector('h1, h2, h3, [data-modal-title]')?.textContent || element.getAttribute('aria-label'), 120),
                    open: true,
                })),
            focused_element: document.activeElement && !isInLara(document.activeElement) ? labelFor(document.activeElement) : null,
        };
    };

    Alpine.data('agentChatComposer', () => ({
        draftKey: 'blb-lara-draft-{{ auth()->id() }}',

        async onSubmit(textarea, scrollContainer) {
            const value = textarea.value.trim();
            const hasAttachments = document.querySelectorAll('[wire\\:key^="att-"]').length > 0;
            if (!value && !hasAttachments) return;

            this.pendingMessage = value || '📎';
            this.$nextTick(() => {
                if (scrollContainer) scrollContainer.scrollTop = scrollContainer.scrollHeight;
            });

            await this.$wire.set('pageUrl', window.location.href);
            await this.$wire.set(
                'activePageSnapshot',
                window.blbCollectLaraActivePageSnapshot(),
            );

            try {
                const result = await this.$wire.prepareStreamingRun();

                if (result?.status === 'session_busy' && result.runId) {
                    this.pendingMessage = null;
                    await this.resumeKnownTurn({
                        runId: result.runId,
                        phase: result.phase,
                        label: result.label,
                        started_at: result.started_at,
                        created_at: result.created_at,
                    }, scrollContainer);
                    this.$nextTick(() => textarea?.focus());
                    return;
                }

                if (result && result.runId && result.replayUrl) {
                    this.resetRunState();
                    this.pendingMessage = null;
                    this.restoreRunState(result.runId) || this.ensureRunState(result.runId);
                    this.selectedRunId = result.runId;
                    this.ensureRunState(result.runId, {
                        streamEntries: [],
                        runPhase: result.phase || 'waiting_for_worker',
                        runLabel: result.label || this.waitingForWorkerLabel,
                        scrollContainer,
                    });
                    this.startElapsedTimer(result.runId, result.started_at || result.created_at || null);
                    this.startReplayPolling(result.runId, scrollContainer);
                    textarea.value = '';
                    window.sharedChatComposerResetHeight?.(textarea);
                    localStorage.removeItem(this.draftKey);
                    return;
                }

                this.pendingMessage = null;
                textarea.value = '';
                window.sharedChatComposerResetHeight?.(textarea);
                localStorage.removeItem(this.draftKey);
            } catch (e) {
                this.ensureRunState(this.selectedRunId)?.streamEntries.push({
                    type: 'error',
                    message: e?.message || 'Failed to start streaming run',
                });
                this.pendingMessage = null;
                this.$wire.finalizeStreamingRun();
            }
        },
    }));

    Alpine.data('agentChatStream', (config = {}) => ({
        pendingMessage: null,
        followTail: true,
        selectedRunId: null,
        runRegistry: {},

        startingLabel: config.startingLabel ?? 'Starting…',
        stoppingLabel: config.stoppingLabel ?? 'Stopping…',
        waitingForWorkerLabel: config.waitingForWorkerLabel ?? 'Waiting for worker…',
        runFailedMessage: config.runFailedMessage ?? 'Turn failed',
        connectionLostMessage: config.connectionLostMessage ?? 'Connection lost. Please try again.',
        reconnectingLabel: config.reconnectingLabel ?? 'Connection interrupted. Reconnecting…',
        reasoningLabel: config.reasoningLabel ?? 'Reasoning…',
        writingLabel: config.writingLabel ?? 'Writing…',
        runningToolLabelTemplate: config.runningToolLabelTemplate ?? 'Running :tool…',
        replayUrlTemplate: config.replayUrlTemplate ?? '',
        terminalTurnStatuses: ['succeeded', 'failed', 'cancelled', 'timed_out'],

        get isBusy() {
            return !!this.pendingMessage || !!this.selectedRunId;
        },

        get selectedRunState() {
            if (!this.selectedRunId) {
                return null;
            }

            return this.runRegistry[this.selectedRunId] || null;
        },

        get streamEntries() {
            return this.selectedRunState?.streamEntries || [];
        },

        get runPhase() {
            return this.selectedRunState?.runPhase || null;
        },

        get runLabel() {
            return this.selectedRunState?.runLabel || null;
        },

        get elapsedSeconds() {
            return this.selectedRunState?.elapsedSeconds || 0;
        },

        get toolsCollapsed() {
            return this.selectedRunState?.toolsCollapsed || false;
        },

        get stopRequested() {
            return !!this.selectedRunState?.stopRequested;
        },

        createRunState() {
            return {
                streamEntries: [],
                runPhase: null,
                runLabel: null,
                runStartedAt: null,
                elapsedSeconds: 0,
                lastSeq: 0,
                toolMap: {},
                completedToolCount: 0,
                toolsCollapsed: false,
                deltaBuffer: '',
                deltaFlushTimer: null,
                scrollContainer: null,
                replayPollTimer: null,
                replayPromise: null,
                pendingReplayAfterSeq: null,
                replayFailureCount: 0,
                replayWarningShown: false,
                abortController: null,
                fetchReader: null,
                elapsedTimer: null,
                stopRequested: false,
                stopRequestedAt: null,
            };
        },

        ensureRunState(runId, patch = {}) {
            if (!runId) {
                return null;
            }

            const state = this.runRegistry[runId] || this.createRunState();
            Object.assign(state, patch);

            if (!this.runRegistry[runId]) {
                this.runRegistry = {
                    ...this.runRegistry,
                    [runId]: state,
                };
            }

            return state;
        },

        snapshotActiveRunState() {
            if (!this.selectedRunId || !this.selectedRunState) {
                return;
            }

            this.runRegistry = {
                ...this.runRegistry,
                [this.selectedRunId]: this.selectedRunState,
            };
        },

        restoreRunState(runId) {
            if (!this.runRegistry[runId]) {
                return false;
            }

            this.selectedRunId = runId;

            return true;
        },

        forgetRunState(runId) {
            if (!runId || !this.runRegistry[runId]) {
                return;
            }

            this.teardownRunState(runId);

            const nextRegistry = { ...this.runRegistry };
            delete nextRegistry[runId];
            this.runRegistry = nextRegistry;
        },

        resetRunState(dropCurrentRegistry = false) {
            const currentRunId = this.selectedRunId;

            if (!currentRunId) {
                return;
            }

            if (dropCurrentRegistry) {
                this.forgetRunState(currentRunId);
            } else {
                this.snapshotActiveRunState();
                this.teardownRunState(currentRunId);
            }

            this.selectedRunId = null;
        },

        teardownRunState(runId) {
            const state = this.runRegistry[runId] || null;
            if (!state) {
                return;
            }

            this.abortPersistentFetch(runId);
            this.stopReplayPolling(runId);
            this.flushDeltaBuffer(runId);
            state.deltaBuffer = '';
            state.scrollContainer = null;
            state.replayPromise = null;
            state.pendingReplayAfterSeq = null;

            if (state.elapsedTimer) {
                clearInterval(state.elapsedTimer);
                state.elapsedTimer = null;
            }
        },

        flushDeltaBuffer(runId = null) {
            const state = this.ensureRunState(runId || this.selectedRunId);
            if (!state) {
                return;
            }

            if (state.deltaFlushTimer) {
                clearTimeout(state.deltaFlushTimer);
                state.deltaFlushTimer = null;
            }

            if (!state.deltaBuffer) {
                return;
            }

            const last = state.streamEntries[state.streamEntries.length - 1];
            if (last && last.type === 'assistant_streaming') {
                last.content += state.deltaBuffer;
            } else {
                state.streamEntries.push({
                    type: 'assistant_streaming',
                    content: state.deltaBuffer,
                });
            }
            state.deltaBuffer = '';
        },

        startElapsedTimer(runId, serverStartedAt = null) {
            const state = this.ensureRunState(runId);
            if (!state) {
                return;
            }

            if (state.elapsedTimer) {
                clearInterval(state.elapsedTimer);
                state.elapsedTimer = null;
            }

            state.runStartedAt = serverStartedAt
                ? new Date(serverStartedAt).getTime()
                : Date.now();

            state.elapsedSeconds = Math.max(0, Math.floor((Date.now() - state.runStartedAt) / 1000));
            state.elapsedTimer = setInterval(() => {
                const liveState = this.runRegistry[runId];
                if (!liveState || !liveState.runStartedAt) {
                    return;
                }

                liveState.elapsedSeconds = Math.max(0, Math.floor((Date.now() - liveState.runStartedAt) / 1000));
            }, 1000);
        },

        async resumeKnownTurn(activeTurn, scrollContainer) {
            if (!activeTurn?.runId) {
                return;
            }

            const runId = activeTurn.runId;
            const timerAnchor = activeTurn.started_at || activeTurn.created_at || null;
            const phase = activeTurn.phase || null;
            const label = activeTurn.label
                || this.labelForPhase(phase, null)
                || this.waitingForWorkerLabel;

            if (this.selectedRunId !== runId) {
                this.resetRunState();
                this.ensureRunState(runId);
                this.restoreRunState(runId);
            }

            const state = this.ensureRunState(runId, {
                runPhase: phase,
                runLabel: label,
                scrollContainer,
            });

            if (timerAnchor) {
                this.startElapsedTimer(runId, timerAnchor);
            }

            if (state && (state.abortController || state.replayPollTimer)) {
                return;
            }

            await this.resumeTurnStream(runId, scrollContainer);
        },

        async onSessionSelected(detail, scrollContainer) {
            const selectedTurn = {
                runId: detail?.activeTurnId || null,
                phase: detail?.activeTurnPhase || null,
                label: detail?.activeTurnLabel || null,
                started_at: detail?.activeTurnStartedAt || null,
                created_at: detail?.activeTurnCreatedAt || null,
            };

            if (!selectedTurn.runId) {
                this.pendingMessage = null;
                this.resetRunState();
                return;
            }

            await this.resumeKnownTurn(selectedTurn, scrollContainer);
        },

        stopReplayPolling(runId = null) {
            const state = this.ensureRunState(runId || this.selectedRunId);
            if (!state?.replayPollTimer) {
                return;
            }

            clearInterval(state.replayPollTimer);
            state.replayPollTimer = null;
        },

        startReplayPolling(runId, scrollContainer) {
            const state = this.ensureRunState(runId);
            if (!runId || !state || state.replayPollTimer) {
                return;
            }

            const poll = () => {
                if (this.selectedRunId !== runId || !this.runRegistry[runId]) {
                    this.stopReplayPolling(runId);

                    return;
                }

                this.requestReplay(state.lastSeq, scrollContainer, runId).then(() => {
                    const liveState = this.runRegistry[runId];

                    if (liveState) {
                        liveState.replayFailureCount = 0;
                    }
                }).catch(() => {
                    const liveState = this.runRegistry[runId];
                    if (!liveState) {
                        return;
                    }

                    liveState.replayFailureCount++;
                    liveState.runLabel = this.reconnectingLabel;

                    if (liveState.replayFailureCount >= 3 && !liveState.replayWarningShown) {
                        liveState.streamEntries.push({ type: 'error', message: this.connectionLostMessage });
                        liveState.replayWarningShown = true;
                    }
                });
            };

            state.replayPollTimer = setInterval(poll, 1000);
            poll();
        },

        finalizeTurnStream(runId = null) {
            const finalizedTurnId = runId || this.selectedRunId;
            const finalizedSessionId = this.$wire.selectedSessionId || null;

            if (finalizedTurnId && this.selectedRunId === finalizedTurnId) {
                this.resetRunState(true);
            } else if (finalizedTurnId) {
                this.forgetRunState(finalizedTurnId);
            }

            if (finalizedSessionId || finalizedTurnId) {
                window.dispatchEvent(new CustomEvent('agent-chat-response-ready', {
                    detail: {
                        runId: finalizedTurnId,
                        sessionId: finalizedSessionId,
                    },
                }));
            }

            this.$wire.finalizeStreamingRun(finalizedTurnId, finalizedSessionId);
        },

        onServerTurnReady(detail) {
            const serverSessionId = detail?.sessionId || null;
            const selectedSessionId = this.$wire.selectedSessionId || null;

            if (serverSessionId && selectedSessionId && serverSessionId !== selectedSessionId) {
                return;
            }

            this.pendingMessage = null;

            const serverTurnId = detail?.runId || null;
            if (!serverTurnId) {
                return;
            }

            if (this.selectedRunId && this.selectedRunId !== serverTurnId) {
                return;
            }

            if (this.selectedRunId === serverTurnId) {
                this.resetRunState(true);
            }
        },

        repairAbandonedSelectedSession(runId) {
            if (!runId || this.selectedRunId !== runId) {
                return;
            }

            this.pendingMessage = null;
            this.resetRunState(true);
        },

        abortPersistentFetch(runId = null) {
            const state = this.ensureRunState(runId || this.selectedRunId);
            if (!state?.abortController) {
                return;
            }

            state.abortController.abort();
            state.abortController = null;
            state.fetchReader = null;
        },

        async startPersistentFetch(runId, streamUrl, scrollContainer) {
            const state = this.ensureRunState(runId, { scrollContainer });
            if (!state) {
                return;
            }

            this.abortPersistentFetch(runId);
            state.abortController = new AbortController();

            try {
                const response = await fetch(streamUrl, {
                    signal: state.abortController.signal,
                    credentials: 'same-origin',
                });

                if (!response.ok || !response.body) {
                    let errorMessage = this.connectionLostMessage;

                    try {
                        const errorBody = await response.text();
                        const errorData = JSON.parse(errorBody);

                        if (errorData.error) errorMessage = errorData.error;
                    } catch {
                        // Fall back to generic message
                    }

                    state.streamEntries.push({ type: 'error', message: errorMessage });
                    this.finalizeTurnStream(runId);

                    return;
                }

                state.fetchReader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await state.fetchReader.read();

                    if (done) break;

                    if (this.selectedRunId !== runId) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (const line of lines) {
                        if (!line.trim()) continue;

                        try {
                            const data = JSON.parse(line);

                            if (data._stream_complete) {
                                if (this.selectedRunId !== runId) return;

                                this.removeThinkingEntries(runId);
                                this.finalizeTurnStream(runId);

                                return;
                            }

                            if (data.error && !data.event_type) {
                                state.streamEntries.push({ type: 'error', message: data.error });
                                this.finalizeTurnStream(runId);

                                return;
                            }

                            await this.handleTurnEvent(data.event_type, data, scrollContainer, runId);
                        } catch {
                            // Skip malformed lines
                        }
                    }
                }

                // Stream ended without _stream_complete — treat as normal completion
                if (this.selectedRunId === runId) {
                    this.removeThinkingEntries(runId);
                    this.finalizeTurnStream(runId);
                }
            } catch (e) {
                if (e.name === 'AbortError') return;

                if (this.selectedRunId === runId) {
                    state.streamEntries.push({ type: 'error', message: this.connectionLostMessage });
                    this.finalizeTurnStream(runId);
                }
            }
        },

        async resumeTurnStream(runId, scrollContainer) {
            this.selectedRunId = runId;
            this.followTail = true;
            this.ensureRunState(runId, { scrollContainer });

            try {
                const json = await this.replayTurnEvents(0, scrollContainer, runId);
                if (!json) {
                    this.repairAbandonedSelectedSession(runId);
                    return;
                }

                const isTerminal = ['succeeded', 'failed', 'cancelled', 'timed_out'].includes(json.status);
                if (isTerminal && this.selectedRunId === runId) {
                    this.repairAbandonedSelectedSession(runId);
                    return;
                }

                if (!isTerminal) {
                    this.startReplayPolling(runId, scrollContainer);
                }
            } catch {
                const state = this.ensureRunState(runId);
                state?.streamEntries.push({ type: 'error', message: this.connectionLostMessage });
                this.finalizeTurnStream(runId);
            }
        },

        async requestReplay(afterSeq, scrollContainer, runId = null) {
            const normalizedAfterSeq = Math.max(0, Number.parseInt(afterSeq ?? 0, 10) || 0);
            const activeTurnId = runId || this.selectedRunId;
            const state = this.ensureRunState(activeTurnId);
            if (!activeTurnId || !state) {
                return null;
            }

            if (state.replayPromise) {
                state.pendingReplayAfterSeq = state.pendingReplayAfterSeq === null
                    ? normalizedAfterSeq
                    : Math.min(state.pendingReplayAfterSeq, normalizedAfterSeq);

                return state.replayPromise;
            }

            state.replayPromise = (async () => {
                let nextAfterSeq = normalizedAfterSeq;

                do {
                    state.pendingReplayAfterSeq = null;
                    await this.replayTurnEvents(nextAfterSeq, scrollContainer, activeTurnId);
                    nextAfterSeq = state.pendingReplayAfterSeq;
                } while (nextAfterSeq !== null && this.selectedRunId === activeTurnId);
            })();

            return state.replayPromise.finally(() => {
                if (this.runRegistry[activeTurnId]) {
                    this.runRegistry[activeTurnId].replayPromise = null;
                    this.runRegistry[activeTurnId].pendingReplayAfterSeq = null;
                }
            });
        },

        async replayTurnEvents(afterSeq, scrollContainer, runId = null) {
            const replayTurnId = runId || this.selectedRunId;
            if (!replayTurnId) return null;

            const replayUrl = this.replayUrlTemplate.replace('__TURN__', replayTurnId) + '?after_seq=' + afterSeq;
            const resp = await fetch(replayUrl, { credentials: 'same-origin' });
            if (!resp.ok) return null;

            const json = await resp.json();

            if (this.selectedRunId !== replayTurnId) {
                return null;
            }

            const state = this.ensureRunState(replayTurnId);
            if (!state) {
                return null;
            }

            if (!state.runStartedAt && (json.started_at || json.created_at)) {
                this.startElapsedTimer(replayTurnId, json.started_at || json.created_at);
            }
            if (json.current_phase) {
                state.runPhase = json.current_phase;
                state.runLabel = (json.cancel_requested_at || state.stopRequested)
                    ? this.stoppingLabel
                    : (json.current_label
                        || this.labelForPhase(json.current_phase, json.current_phase));
            }
            if (json.cancel_requested_at) {
                state.stopRequested = true;
                state.stopRequestedAt = json.cancel_requested_at;
                state.runLabel = this.stoppingLabel;
            }

            for (const event of (json.events || [])) {
                const eventSeq = Number.parseInt(event?.seq ?? 0, 10) || 0;

                if (eventSeq && eventSeq <= state.lastSeq) {
                    continue;
                }

                this.processTurnEvent(event.event_type, event, scrollContainer, replayTurnId);

                if (this.selectedRunId !== replayTurnId) {
                    break;
                }
            }

            if (this.terminalTurnStatuses.includes(json.status) && this.selectedRunId === replayTurnId) {
                this.removeThinkingEntries(replayTurnId);
                this.finalizeTurnStream(replayTurnId);
            }

            return json;
        },

        async handleTurnEvent(eventType, data, scrollContainer, runId = null) {
            const seq = Number.parseInt(data?.seq ?? 0, 10) || 0;
            const activeTurnId = runId || data.run_id || this.selectedRunId;
            const state = this.ensureRunState(activeTurnId);
            if (!activeTurnId || !state) {
                return;
            }

            this.selectedRunId = activeTurnId;

            if (seq && seq > state.lastSeq + 1) {
                try {
                    await this.requestReplay(state.lastSeq, scrollContainer, activeTurnId);
                } catch {
                    state.streamEntries.push({ type: 'error', message: this.connectionLostMessage });
                    this.finalizeTurnStream(activeTurnId);
                }

                return;
            }

            if (seq && seq <= state.lastSeq) {
                return;
            }

            this.processTurnEvent(eventType, data, scrollContainer, activeTurnId);
        },

        processTurnEvent(eventType, data, scrollContainer, runId = null) {
            const activeTurnId = runId || data.run_id || this.selectedRunId;
            const state = this.ensureRunState(activeTurnId);
            if (!activeTurnId || !state) {
                return;
            }

            if (data.seq) state.lastSeq = data.seq;

            switch (eventType) {
                case 'run.started':
                    this.onTurnStarted(data, activeTurnId);
                    break;

                case 'run.phase_changed':
                    this.onPhaseChanged(data, activeTurnId);
                    break;

                case 'assistant.thinking_started':
                    this.onThinkingStarted(data, activeTurnId);
                    break;

                case 'assistant.thinking_delta':
                    this.onThinkingDelta(data, activeTurnId);
                    break;

                case 'tool.started':
                    this.onToolStarted(data, activeTurnId);
                    break;

                case 'tool.finished':
                    this.onToolFinished(data, activeTurnId);
                    break;

                case 'tool.stdout_delta':
                    this.onToolStdoutDelta(data, activeTurnId);
                    break;

                case 'tool.denied':
                    this.onToolDenied(data, activeTurnId);
                    break;

                case 'assistant.output_delta':
                    this.onOutputDelta(data, activeTurnId);
                    break;

                case 'assistant.output_block_committed':
                    this.flushDeltaBuffer(activeTurnId);
                    break;

                case 'run.completed':
                case 'run.ready_for_input':
                    this.removeThinkingEntries(activeTurnId);
                    this.finalizeTurnStream(activeTurnId);
                    return;

                case 'run.failed':
                    this.onTurnFailed(data, activeTurnId);
                    return;

                case 'run.cancelled':
                    this.finalizeTurnStream(activeTurnId);
                    return;
            }

            this.scrollToBottom(scrollContainer);
        },

        onTurnStarted(data, runId) {
            const payload = data?.payload || data || {};
            const serverStartedAt = payload.started_at || null;
            const state = this.ensureRunState(runId);
            if (!state) {
                return;
            }

            if (serverStartedAt) {
                this.startElapsedTimer(runId, serverStartedAt);
            } else if (!state.runStartedAt) {
                this.startElapsedTimer(runId);
            }
            state.runPhase = 'booting';
            state.runLabel = this.startingLabel;
        },

        onPhaseChanged(data, runId) {
            const phase = data.payload?.phase || data.phase;
            const label = data.payload?.label
                || data.label
                || this.labelForPhase(phase, phase);
            const state = this.ensureRunState(runId);
            if (!state) {
                return;
            }

            state.runPhase = phase;
            state.runLabel = label;

            // Update the most recent thinking entry description when we have a richer label.
            if (phase === 'awaiting_llm' && label && label !== this.phaseLabels?.awaiting_llm) {
                for (let i = state.streamEntries.length - 1; i >= 0; i--) {
                    if (state.streamEntries[i].type === 'thinking') {
                        state.streamEntries[i].description = label.replace(/^(?:Thinking|Working|Awaiting model response)\s*—\s*/u, '');
                        break;
                    }
                }
            }
        },

        onThinkingStarted(data, runId) {
            const payload = data?.payload || data || {};
            const description = payload.description || null;
            const state = this.ensureRunState(runId);
            if (!state) {
                return;
            }

            state.runPhase = 'awaiting_llm';
            state.runLabel = this.reasoningLabel;
            state.streamEntries.push({ type: 'thinking', active: true, description, thinkingContent: '' });
        },

        onThinkingDelta(data, runId) {
            const payload = data?.payload || data || {};
            const delta = payload.delta || '';
            if (!delta) return;
            const state = this.ensureRunState(runId);
            if (!state) {
                return;
            }

            state.runPhase = 'awaiting_llm';
            state.runLabel = this.reasoningLabel;

            // Append to the last thinking entry
            let entry = null;
            for (let i = state.streamEntries.length - 1; i >= 0; i--) {
                if (state.streamEntries[i].type === 'thinking') {
                    entry = state.streamEntries[i];
                    break;
                }
            }

            if (!entry) {
                entry = { type: 'thinking', active: true, description: null, thinkingContent: '' };
                state.streamEntries.push(entry);
            }
            entry.active = true;
            entry.thinkingContent = (entry.thinkingContent || '') + delta;
        },

        onToolStarted(data, runId) {
            const state = this.ensureRunState(runId);
            if (!state) {
                return;
            }

            this.deactivateThinking(runId);

            if (state.completedToolCount > 0 && !state.toolsCollapsed) {
                state.toolsCollapsed = true;
            }

            const payload = data.payload || data;
            state.runPhase = 'running_tool';
            state.runLabel = this.labelForRunningTool(payload.tool || '');

            const idx = state.streamEntries.length;
            const toolKey = payload.tool_call_index ?? idx;
            state.toolMap[toolKey] = idx;

            state.streamEntries.push({
                type: 'tool_use',
                tool: payload.tool || '',
                argsSummary: payload.args_summary || '',
                displaySummary: payload.display_summary || '',
                status: 'running',
                stdoutBuffer: '',
                collapsed: false,
            });
        },

        onToolFinished(data, runId) {
            const payload = data.payload || data;
            const toolKey = payload.tool_call_index ?? -1;
            const state = this.ensureRunState(runId);
            if (!state) {
                return;
            }

            const callIdx = state.toolMap[toolKey];

            if (callIdx === undefined || !state.streamEntries[callIdx]) {
                return;
            }

            const entry = state.streamEntries[callIdx];
            entry.status = payload.status || 'success';
            entry.durationMs = payload.duration_ms;
            entry.resultPreview = payload.result_preview || '';
            entry.resultLength = payload.result_length || 0;
            entry.errorPayload = payload.error_payload || null;
            state.completedToolCount++;
            state.runPhase = 'awaiting_llm';
            state.runLabel = this.labelForPhase('awaiting_llm', this.phaseLabels?.awaiting_llm || 'Awaiting model response…');
        },

        onToolStdoutDelta(data, runId) {
            const payload = data.payload || data;
            const toolName = payload.tool || '';
            const delta = payload.delta || '';
            if (!delta) return;
            const state = this.ensureRunState(runId);
            if (!state) {
                return;
            }

            // Find the last running tool_use entry matching this tool
            for (let i = state.streamEntries.length - 1; i >= 0; i--) {
                const entry = state.streamEntries[i];
                if (entry.type === 'tool_use' && entry.tool === toolName && entry.status === 'running') {
                    // Cap buffer at 10KB to prevent DOM bloat
                    if ((entry.stdoutBuffer || '').length < 10240) {
                        entry.stdoutBuffer = (entry.stdoutBuffer || '') + delta;
                    }
                    break;
                }
            }
        },

        onToolDenied(data, runId) {
            const payload = data.payload || data;
            this.ensureRunState(runId)?.streamEntries.push({
                type: 'hook_action',
                stage: 'pre_tool_use',
                action: 'tool_denied',
                tool: payload.tool || '',
                reason: payload.reason || '',
                source: payload.source || 'hook',
            });
        },

        onOutputDelta(data, runId) {
            const state = this.ensureRunState(runId);
            if (!state) {
                return;
            }

            this.deactivateThinking(runId);
            state.runPhase = 'streaming_answer';
            state.runLabel = this.writingLabel;

            const payload = data.payload || data;
            const text = payload.delta || payload.text || '';
            if (!text) return;

            state.deltaBuffer += text;

            const hasBoundary = /\n/.test(text);
            if (hasBoundary) {
                this.flushDeltaBuffer(runId);

                return;
            }

            if (state.deltaFlushTimer) clearTimeout(state.deltaFlushTimer);
            state.deltaFlushTimer = setTimeout(() => this.flushDeltaBuffer(runId), 80);
        },

        onTurnFailed(data, runId) {
            const payload = data.payload || data;
            this.ensureRunState(runId)?.streamEntries.push({
                type: 'error',
                message: payload.message || this.runFailedMessage,
            });
            this.finalizeTurnStream(runId);
        },

        labelForRunningTool(tool) {
            if (!tool) {
                return this.labelForPhase('running_tool', 'Running tool…');
            }

            return this.runningToolLabelTemplate.replace(':tool', tool);
        },

        deactivateThinking(runId = null) {
            const state = this.ensureRunState(runId || this.selectedRunId);
            if (!state) {
                return;
            }

            for (let i = state.streamEntries.length - 1; i >= 0; i--) {
                if (state.streamEntries[i].type === 'thinking') {
                    state.streamEntries[i].active = false;
                    break;
                }
            }
        },

        removeThinkingEntries(runId = null) {
            const state = this.ensureRunState(runId || this.selectedRunId);
            if (!state) {
                return;
            }

            state.streamEntries = state.streamEntries.filter(
                (entry) => entry.type !== 'thinking' || (entry.thinkingContent && entry.thinkingContent.trim()),
            );
        },

        stopStreaming() {
            this.pendingMessage = null;

            if (!this.selectedRunId) {
                return;
            }

            const runId = this.selectedRunId;
            const state = this.ensureRunState(runId);
            if (!state) {
                return;
            }

            if (state.stopRequested) {
                return;
            }

            state.stopRequested = true;
            state.stopRequestedAt = new Date().toISOString();
            state.runLabel = this.stoppingLabel;
            this.$wire.cancelActiveTurn(runId);
            this.startReplayPolling(runId, state.scrollContainer);
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
