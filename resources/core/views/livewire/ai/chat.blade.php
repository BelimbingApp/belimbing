<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

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
        pageAwareness: localStorage.getItem('blb-lara-page-awareness') || 'page',
        _draftKey: 'blb-lara-draft-{{ auth()->id() }}',
        _sessionDragging: false,
        activeTurnSummaries: @js($activeTurnsBySession ?? []),
        replayUrlTemplate: @js(route('ai.chat.turn.events', ['turnId' => '__TURN__'])),
        terminalTurnStatuses: ['completed', 'failed', 'cancelled'],
        phaseLabels: @js($phaseLabels),
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

        replayUrlFor(turnId, afterSeq = 0) {
            return this.replayUrlTemplate.replace('__TURN__', turnId) + '?after_seq=' + afterSeq;
        },

        labelForPhase(phase, fallback = null) {
            if (phase && this.phaseLabels[phase]) {
                return this.phaseLabels[phase];
            }

            return fallback;
        },

        syncSummary(sessionId, patch) {
            const summaries = this.activeTurnSummaries ?? {};
            const current = summaries[sessionId] || {};
            this.activeTurnSummaries = {
                ...summaries,
                [sessionId]: {
                    ...current,
                    ...patch,
                },
            };
        },

        clearSummary(sessionId, turnId = null) {
            const current = (this.activeTurnSummaries ?? {})[sessionId] || null;
            if (!current) {
                return;
            }

            if (turnId && current.turnId && current.turnId !== turnId) {
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
            if (!summary?.turnId) {
                this.clearSummary(sessionId);
                return;
            }

            const afterSeq = summary.lastSeq || 0;
            const response = await fetch(this.replayUrlFor(summary.turnId, afterSeq), {
                credentials: 'same-origin',
            });

            if (!response.ok) {
                this.clearSummary(sessionId, summary.turnId);
                return;
            }

            const payload = await response.json();
            const latestSeq = (payload.events || []).reduce((max, event) => Math.max(max, Number.parseInt(event?.seq ?? 0, 10) || 0), afterSeq);

            if (this.terminalTurnStatuses.includes(payload.status)) {
                this.clearSummary(sessionId, summary.turnId);
                return;
            }

            this.syncSummary(sessionId, {
                turnId: summary.turnId,
                session_id: sessionId,
                status: payload.status,
                phase: payload.current_phase || summary.phase || null,
                label: payload.current_label
                    || this.labelForPhase(payload.current_phase, null)
                    || summary.label
                    || null,
                started_at: payload.started_at || summary.started_at || null,
                created_at: payload.created_at || summary.created_at || null,
                timer_anchor_at: payload.started_at || payload.created_at || summary.timer_anchor_at || null,
                lastSeq: latestSeq,
            });
        },

        startSummaryPolling() {
            if (this._summaryPollTimer || Object.keys(this.activeTurnSummaries ?? {}).length === 0) {
                return;
            }

            const poll = () => {
                Object.entries(this.activeTurnSummaries ?? {}).forEach(([sessionId, summary]) => {
                    this.refreshSummary(sessionId, summary).catch(() => {
                        this.clearSummary(sessionId, summary?.turnId || null);
                    });
                });
            };

            this._summaryPollTimer = setInterval(poll, 2000);
            poll();
        },

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
        const savedSession = localStorage.getItem('blb-lara-session');
        if (savedSession && !$wire.selectedSessionId) {
            $wire.set('selectedSessionId', savedSession);
        }
        $nextTick(() => $refs.agentComposer?.focus());
        $wire.set('pageUrl', window.location.href);
        $watch('pageAwareness', v => $wire.set('pageAwareness', v));
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
        if (Object.keys($data.activeTurnSummaries ?? {}).length > 0) {
            $data.startSummaryPolling();
        }
    "
    @navigate.window="$wire.set('pageUrl', window.location.href)"
    @agent-chat-response-ready.window="if ($event.detail?.sessionId) clearSummary($event.detail.sessionId, $event.detail?.turnId || null)"
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
                                        @if ($canAccessControlPlane && isset($sessionTurnTargets[$session->id]))
                                            <div class="mt-0.5">
                                                <a
                                                    href="{{ route('admin.ai.control-plane', ['tab' => 'turns', 'turnId' => $sessionTurnTargets[$session->id]['turn_id']]) }}"
                                                    wire:navigate
                                                    class="text-[10px] text-accent hover:underline"
                                                >
                                                    {{ $sessionTurnTargets[$session->id]['is_active'] ? __('Current Turn') : __('Last Turn') }}:
                                                    <span class="font-mono">{{ \Illuminate\Support\Str::limit($sessionTurnTargets[$session->id]['turn_id'], 14, '...') }}</span>
                                                </a>
                                            </div>
                                        @endif
                                        <div
                                            x-show="activeTurnSummaries['{{ $session->id }}']"
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
                                        x-show="activeTurnSummaries['{{ $session->id }}']"
                                        x-cloak
                                        x-on:click.stop="$wire.cancelActiveTurn(activeTurnSummaries['{{ $session->id }}']?.turnId)"
                                        class="text-muted hover:text-ink p-1 shrink-0"
                                        title="{{ __('Stop active turn') }}"
                                        aria-label="{{ __('Stop active turn') }}"
                                    >
                                        <x-icon name="heroicon-o-stop" class="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        x-show="!activeTurnSummaries['{{ $session->id }}']"
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
                    stoppingLabel: @js(__('Stopping…')),
                    waitingForWorkerLabel: @js(__('Waiting for worker…')),
                    turnFailedMessage: @js(__('Turn failed')),
                    connectionLostMessage: @js(__('Connection lost. Please try again.')),
                    replayUrlTemplate: @js(route('ai.chat.turn.events', ['turnId' => '__TURN__'])),
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
                        if (selectedTurn?.turnId) {
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
                            $messageTokens = $message->getMetaInt('tokens');
                            $messageLatencyMs = $message->getMetaInt('latency_ms');
                            $messageTimeoutSeconds = $message->getMetaInt('timeout_seconds');
                            $messageRetryAttempts = $message->getMetaInt('retry_attempts');
                            $messageFallbackAttempts = $message->getMetaArray('fallback_attempts');
                            $messageErrorType = $message->getMetaString('error_type');
                            $messageErrorMessage = $message->getMetaString('error');
                            $messageRunStatus = $message->getMetaString('status');
                            $messageStopNote = $message->getMetaString('stop_note');
                            $messageTool = $message->getMetaString('tool', '');
                            $messageArgsSummary = $message->getMetaString('args_summary', '{}');
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
                                :fallback-attempts="$messageFallbackAttempts"
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
                                :stop-note="$messageStopNote"
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
                                            <template x-if="entry.argsSummary">
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

                {{-- Fallback banner --}}
                @if ($showSessionFallbackBanner && $sessionFallbackBannerAttempt !== null)
                    <div
                        x-data="{ dismissed: false }"
                        x-show="!dismissed"
                        x-cloak
                        class="border-t border-amber-500/20 bg-amber-500/5 px-4 py-2 flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400"
                    >
                        <x-icon name="heroicon-o-exclamation-triangle" class="w-4 h-4 shrink-0 mt-0.5" />
                        <div class="flex-1 min-w-0">
                            <span>{{ __('Earlier in this conversation, :provider/:model reported: :error.', [
                                'provider' => $sessionFallbackBannerAttempt['provider'] ?? '?',
                                'model' => $sessionFallbackBannerAttempt['model'] ?? '?',
                                'error' => $sessionFallbackBannerAttempt['error'] ?? __('unknown error'),
                            ]) }}</span>
                            <span class="text-muted">{{ __('If this keeps happening, try another model or retry in a few minutes.') }}</span>
                        </div>
                        <button type="button" @click="dismissed = true" class="shrink-0 text-amber-500 hover:text-amber-700 dark:hover:text-amber-300" aria-label="{{ __('Dismiss') }}">
                            <x-icon name="heroicon-o-x-mark" class="w-3.5 h-3.5" />
                        </button>
                    </div>
                @endif

                {{-- Sticky turn status bar (coding-agent console) --}}
                <div
                    x-show="isBusy"
                    x-cloak
                    class="border-t border-border-default bg-surface-subtle/60 px-4 py-1.5 flex items-center gap-3 text-xs shrink-0"
                >
                    <span class="w-2 h-2 bg-accent rounded-full animate-pulse shrink-0"></span>
                    <span class="text-muted truncate" x-text="turnLabel || '{{ __('Processing…') }}'"></span>
                    <span class="tabular-nums text-muted/70 shrink-0" x-text="
                        elapsedSeconds < 60
                            ? elapsedSeconds + 's'
                            : Math.floor(elapsedSeconds / 60) + 'm ' + (elapsedSeconds % 60) + 's'
                    "></span>
                    <button
                        type="button"
                        @click="stopStreaming()"
                        class="ml-auto inline-flex items-center gap-1 px-2 py-0.5 rounded-full border border-border-default bg-surface-card text-muted hover:text-ink hover:border-accent/40 transition-colors shrink-0"
                    >
                        <x-icon name="heroicon-o-stop" class="w-3 h-3" />
                        {{ __('Stop') }}
                    </button>
                </div>

                {{-- Composer --}}
                <div class="border-t border-border-default px-4 py-3 space-y-2">

                    {{-- Model picker + session usage --}}
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            @if ($canSelectModel && count($availableModels) > 0)
                                <div class="flex items-center gap-1 min-w-0">
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
                                <div class="inline-flex items-center gap-1 text-[11px] text-muted min-w-0">
                                    <x-icon name="heroicon-o-cpu-chip" class="w-3 h-3 shrink-0" />
                                    <span class="truncate max-w-[12rem]">{{ $currentModel }}</span>
                                </div>
                            @endif

                            <x-ui.button variant="ghost" size="sm" wire:click="createSession" class="shrink-0">
                                <x-icon name="heroicon-o-plus" class="w-3 h-3" />
                                <span>{{ __('New session') }}</span>
                            </x-ui.button>
                            @if ($canAccessControlPlane && $selectedSessionTurnTarget)
                                <a
                                    href="{{ route('admin.ai.control-plane', ['tab' => 'turns', 'turnId' => $selectedSessionTurnTarget['turn_id']]) }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-1 rounded-full border border-border-default bg-surface-card px-2 py-1 text-[11px] text-accent hover:border-accent/40 hover:bg-surface-subtle"
                                >
                                    <x-icon name="heroicon-o-adjustments-horizontal" class="h-3 w-3" />
                                    <span>{{ __('Open in Control Plane') }}</span>
                                </a>
                            @endif
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

            try {
                const result = await this.$wire.prepareStreamingRun();

                if (result?.status === 'session_busy' && result.turnId) {
                    this.pendingMessage = null;
                    await this.resumeKnownTurn({
                        turnId: result.turnId,
                        phase: result.phase,
                        label: result.label,
                        started_at: result.started_at,
                        created_at: result.created_at,
                    }, scrollContainer);
                    this.$nextTick(() => textarea?.focus());
                    return;
                }

                if (result && result.turnId && result.streamUrl) {
                    this.resetTurnState();
                    this.pendingMessage = null;
                    this.restoreTurnState(result.turnId) || this.ensureTurnState(result.turnId);
                    this.selectedTurnId = result.turnId;
                    this.ensureTurnState(result.turnId, {
                        streamEntries: [],
                        turnPhase: result.phase || 'waiting_for_worker',
                        turnLabel: result.label || this.waitingForWorkerLabel,
                        scrollContainer,
                    });
                    this.startElapsedTimer(result.turnId, result.started_at || result.created_at || null);
                    this.startPersistentFetch(result.turnId, result.streamUrl, scrollContainer);
                    textarea.value = '';
                    textarea.style.height = 'auto';
                    localStorage.removeItem(this.draftKey);
                    return;
                }

                this.pendingMessage = null;
                textarea.value = '';
                textarea.style.height = 'auto';
                localStorage.removeItem(this.draftKey);
            } catch (e) {
                this.ensureTurnState(this.selectedTurnId)?.streamEntries.push({
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
        selectedTurnId: null,
        turnRegistry: {},

        startingLabel: config.startingLabel ?? 'Starting…',
        stoppingLabel: config.stoppingLabel ?? 'Stopping…',
        waitingForWorkerLabel: config.waitingForWorkerLabel ?? 'Waiting for worker…',
        turnFailedMessage: config.turnFailedMessage ?? 'Turn failed',
        connectionLostMessage: config.connectionLostMessage ?? 'Connection lost. Please try again.',
        replayUrlTemplate: config.replayUrlTemplate ?? '',

        get isBusy() {
            return !!this.pendingMessage || !!this.selectedTurnId;
        },

        get selectedTurnState() {
            if (!this.selectedTurnId) {
                return null;
            }

            return this.turnRegistry[this.selectedTurnId] || null;
        },

        get streamEntries() {
            return this.selectedTurnState?.streamEntries || [];
        },

        get turnPhase() {
            return this.selectedTurnState?.turnPhase || null;
        },

        get turnLabel() {
            return this.selectedTurnState?.turnLabel || null;
        },

        get elapsedSeconds() {
            return this.selectedTurnState?.elapsedSeconds || 0;
        },

        get toolsCollapsed() {
            return this.selectedTurnState?.toolsCollapsed || false;
        },

        createTurnState() {
            return {
                streamEntries: [],
                turnPhase: null,
                turnLabel: null,
                turnStartedAt: null,
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
                abortController: null,
                fetchReader: null,
                elapsedTimer: null,
            };
        },

        ensureTurnState(turnId, patch = {}) {
            if (!turnId) {
                return null;
            }

            const state = this.turnRegistry[turnId] || this.createTurnState();
            Object.assign(state, patch);

            if (!this.turnRegistry[turnId]) {
                this.turnRegistry = {
                    ...this.turnRegistry,
                    [turnId]: state,
                };
            }

            return state;
        },

        snapshotActiveTurnState() {
            if (!this.selectedTurnId || !this.selectedTurnState) {
                return;
            }

            this.turnRegistry = {
                ...this.turnRegistry,
                [this.selectedTurnId]: this.selectedTurnState,
            };
        },

        restoreTurnState(turnId) {
            if (!this.turnRegistry[turnId]) {
                return false;
            }

            this.selectedTurnId = turnId;

            return true;
        },

        forgetTurnState(turnId) {
            if (!turnId || !this.turnRegistry[turnId]) {
                return;
            }

            this.teardownTurnState(turnId);

            const nextRegistry = { ...this.turnRegistry };
            delete nextRegistry[turnId];
            this.turnRegistry = nextRegistry;
        },

        resetTurnState(dropCurrentRegistry = false) {
            const currentTurnId = this.selectedTurnId;

            if (!currentTurnId) {
                return;
            }

            if (dropCurrentRegistry) {
                this.forgetTurnState(currentTurnId);
            } else {
                this.snapshotActiveTurnState();
                this.teardownTurnState(currentTurnId);
            }

            this.selectedTurnId = null;
        },

        teardownTurnState(turnId) {
            const state = this.turnRegistry[turnId] || null;
            if (!state) {
                return;
            }

            this.abortPersistentFetch(turnId);
            this.stopReplayPolling(turnId);
            this.flushDeltaBuffer(turnId);
            state.deltaBuffer = '';
            state.scrollContainer = null;
            state.replayPromise = null;
            state.pendingReplayAfterSeq = null;

            if (state.elapsedTimer) {
                clearInterval(state.elapsedTimer);
                state.elapsedTimer = null;
            }
        },

        flushDeltaBuffer(turnId = null) {
            const state = this.ensureTurnState(turnId || this.selectedTurnId);
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

        startElapsedTimer(turnId, serverStartedAt = null) {
            const state = this.ensureTurnState(turnId);
            if (!state) {
                return;
            }

            if (state.elapsedTimer) {
                clearInterval(state.elapsedTimer);
                state.elapsedTimer = null;
            }

            state.turnStartedAt = serverStartedAt
                ? new Date(serverStartedAt).getTime()
                : Date.now();

            state.elapsedSeconds = Math.max(0, Math.floor((Date.now() - state.turnStartedAt) / 1000));
            state.elapsedTimer = setInterval(() => {
                const liveState = this.turnRegistry[turnId];
                if (!liveState || !liveState.turnStartedAt) {
                    return;
                }

                liveState.elapsedSeconds = Math.max(0, Math.floor((Date.now() - liveState.turnStartedAt) / 1000));
            }, 1000);
        },

        async resumeKnownTurn(activeTurn, scrollContainer) {
            if (!activeTurn?.turnId) {
                return;
            }

            const turnId = activeTurn.turnId;
            const timerAnchor = activeTurn.started_at || activeTurn.created_at || null;
            const phase = activeTurn.phase || null;
            const label = activeTurn.label
                || this.labelForPhase(phase, null)
                || this.waitingForWorkerLabel;

            if (this.selectedTurnId !== turnId) {
                this.resetTurnState();
                this.ensureTurnState(turnId);
                this.restoreTurnState(turnId);
            }

            const state = this.ensureTurnState(turnId, {
                turnPhase: phase,
                turnLabel: label,
                scrollContainer,
            });

            if (timerAnchor) {
                this.startElapsedTimer(turnId, timerAnchor);
            }

            if (state && (state.abortController || state.replayPollTimer)) {
                return;
            }

            await this.resumeTurnStream(turnId, scrollContainer);
        },

        async onSessionSelected(detail, scrollContainer) {
            const selectedTurn = {
                turnId: detail?.activeTurnId || null,
                phase: detail?.activeTurnPhase || null,
                label: detail?.activeTurnLabel || null,
                started_at: detail?.activeTurnStartedAt || null,
                created_at: detail?.activeTurnCreatedAt || null,
            };

            if (!selectedTurn.turnId) {
                this.pendingMessage = null;
                this.resetTurnState();
                return;
            }

            await this.resumeKnownTurn(selectedTurn, scrollContainer);
        },

        stopReplayPolling(turnId = null) {
            const state = this.ensureTurnState(turnId || this.selectedTurnId);
            if (!state?.replayPollTimer) {
                return;
            }

            clearInterval(state.replayPollTimer);
            state.replayPollTimer = null;
        },

        startReplayPolling(turnId, scrollContainer) {
            const state = this.ensureTurnState(turnId);
            if (!turnId || !state || state.replayPollTimer) {
                return;
            }

            const poll = () => {
                if (this.selectedTurnId !== turnId || !this.turnRegistry[turnId]) {
                    this.stopReplayPolling(turnId);

                    return;
                }

                this.requestReplay(state.lastSeq, scrollContainer, turnId).catch(() => {
                    this.stopReplayPolling(turnId);
                    state.streamEntries.push({ type: 'error', message: this.connectionLostMessage });
                    this.finalizeTurnStream(turnId);
                });
            };

            state.replayPollTimer = setInterval(poll, 1000);
            poll();
        },

        finalizeTurnStream(turnId = null) {
            const finalizedTurnId = turnId || this.selectedTurnId;
            const finalizedSessionId = this.$wire.selectedSessionId || null;

            if (finalizedTurnId && this.selectedTurnId === finalizedTurnId) {
                this.resetTurnState(true);
            } else if (finalizedTurnId) {
                this.forgetTurnState(finalizedTurnId);
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

            const serverTurnId = detail?.turnId || null;
            if (!serverTurnId) {
                return;
            }

            if (this.selectedTurnId && this.selectedTurnId !== serverTurnId) {
                return;
            }

            if (this.selectedTurnId === serverTurnId) {
                this.resetTurnState(true);
            }
        },

        repairAbandonedSelectedSession(turnId) {
            if (!turnId || this.selectedTurnId !== turnId) {
                return;
            }

            this.pendingMessage = null;
            this.resetTurnState(true);
        },

        abortPersistentFetch(turnId = null) {
            const state = this.ensureTurnState(turnId || this.selectedTurnId);
            if (!state?.abortController) {
                return;
            }

            state.abortController.abort();
            state.abortController = null;
            state.fetchReader = null;
        },

        async startPersistentFetch(turnId, streamUrl, scrollContainer) {
            const state = this.ensureTurnState(turnId, { scrollContainer });
            if (!state) {
                return;
            }

            this.abortPersistentFetch(turnId);
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
                    this.finalizeTurnStream(turnId);

                    return;
                }

                state.fetchReader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await state.fetchReader.read();

                    if (done) break;

                    if (this.selectedTurnId !== turnId) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop();

                    for (const line of lines) {
                        if (!line.trim()) continue;

                        try {
                            const data = JSON.parse(line);

                            if (data._stream_complete) {
                                if (this.selectedTurnId !== turnId) return;

                                this.removeThinkingEntries(turnId);
                                this.finalizeTurnStream(turnId);

                                return;
                            }

                            if (data.error && !data.event_type) {
                                state.streamEntries.push({ type: 'error', message: data.error });
                                this.finalizeTurnStream(turnId);

                                return;
                            }

                            await this.handleTurnEvent(data.event_type, data, scrollContainer, turnId);
                        } catch {
                            // Skip malformed lines
                        }
                    }
                }

                // Stream ended without _stream_complete — treat as normal completion
                if (this.selectedTurnId === turnId) {
                    this.removeThinkingEntries(turnId);
                    this.finalizeTurnStream(turnId);
                }
            } catch (e) {
                if (e.name === 'AbortError') return;

                if (this.selectedTurnId === turnId) {
                    state.streamEntries.push({ type: 'error', message: this.connectionLostMessage });
                    this.finalizeTurnStream(turnId);
                }
            }
        },

        async resumeTurnStream(turnId, scrollContainer) {
            this.selectedTurnId = turnId;
            this.followTail = true;
            this.ensureTurnState(turnId, { scrollContainer });

            try {
                const json = await this.replayTurnEvents(0, scrollContainer, turnId);
                if (!json) {
                    this.repairAbandonedSelectedSession(turnId);
                    return;
                }

                const isTerminal = ['completed', 'failed', 'cancelled'].includes(json.status);
                if (isTerminal && this.selectedTurnId === turnId) {
                    this.repairAbandonedSelectedSession(turnId);
                    return;
                }

                if (!isTerminal) {
                    this.startReplayPolling(turnId, scrollContainer);
                }
            } catch {
                const state = this.ensureTurnState(turnId);
                state?.streamEntries.push({ type: 'error', message: this.connectionLostMessage });
                this.finalizeTurnStream(turnId);
            }
        },

        async requestReplay(afterSeq, scrollContainer, turnId = null) {
            const normalizedAfterSeq = Math.max(0, Number.parseInt(afterSeq ?? 0, 10) || 0);
            const activeTurnId = turnId || this.selectedTurnId;
            const state = this.ensureTurnState(activeTurnId);
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
                } while (nextAfterSeq !== null && this.selectedTurnId === activeTurnId);
            })();

            return state.replayPromise.finally(() => {
                if (this.turnRegistry[activeTurnId]) {
                    this.turnRegistry[activeTurnId].replayPromise = null;
                    this.turnRegistry[activeTurnId].pendingReplayAfterSeq = null;
                }
            });
        },

        async replayTurnEvents(afterSeq, scrollContainer, turnId = null) {
            const replayTurnId = turnId || this.selectedTurnId;
            if (!replayTurnId) return null;

            const replayUrl = this.replayUrlTemplate.replace('__TURN__', replayTurnId) + '?after_seq=' + afterSeq;
            const resp = await fetch(replayUrl);
            if (!resp.ok) return null;

            const json = await resp.json();

            if (this.selectedTurnId !== replayTurnId) {
                return null;
            }

            const state = this.ensureTurnState(replayTurnId);
            if (!state) {
                return null;
            }

            if (!state.turnStartedAt && (json.started_at || json.created_at)) {
                this.startElapsedTimer(replayTurnId, json.started_at || json.created_at);
            }
            if (json.current_phase) {
                state.turnPhase = json.current_phase;
                state.turnLabel = json.current_label
                    || this.labelForPhase(json.current_phase, json.current_phase);
            }

            for (const event of (json.events || [])) {
                const eventSeq = Number.parseInt(event?.seq ?? 0, 10) || 0;

                if (eventSeq && eventSeq <= state.lastSeq) {
                    continue;
                }

                this.processTurnEvent(event.event_type, event, scrollContainer, replayTurnId);

                if (this.selectedTurnId !== replayTurnId) {
                    break;
                }
            }

            return json;
        },

        async handleTurnEvent(eventType, data, scrollContainer, turnId = null) {
            const seq = Number.parseInt(data?.seq ?? 0, 10) || 0;
            const activeTurnId = turnId || data.turn_id || this.selectedTurnId;
            const state = this.ensureTurnState(activeTurnId);
            if (!activeTurnId || !state) {
                return;
            }

            this.selectedTurnId = activeTurnId;

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

        processTurnEvent(eventType, data, scrollContainer, turnId = null) {
            const activeTurnId = turnId || data.turn_id || this.selectedTurnId;
            const state = this.ensureTurnState(activeTurnId);
            if (!activeTurnId || !state) {
                return;
            }

            if (data.seq) state.lastSeq = data.seq;

            switch (eventType) {
                case 'turn.started':
                    this.onTurnStarted(data, activeTurnId);
                    break;

                case 'turn.phase_changed':
                    this.onPhaseChanged(data, activeTurnId);
                    break;

                case 'run.started':
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

                case 'turn.completed':
                case 'turn.ready_for_input':
                    this.removeThinkingEntries(activeTurnId);
                    this.finalizeTurnStream(activeTurnId);
                    return;

                case 'turn.failed':
                    this.onTurnFailed(data, activeTurnId);
                    return;

                case 'turn.cancelled':
                    this.finalizeTurnStream(activeTurnId);
                    return;
            }

            this.scrollToBottom(scrollContainer);
        },

        onTurnStarted(data, turnId) {
            const payload = data?.payload || data || {};
            const serverStartedAt = payload.started_at || null;
            const state = this.ensureTurnState(turnId);
            if (!state) {
                return;
            }

            if (serverStartedAt) {
                this.startElapsedTimer(turnId, serverStartedAt);
            } else if (!state.turnStartedAt) {
                this.startElapsedTimer(turnId);
            }
            state.turnPhase = 'booting';
            state.turnLabel = this.startingLabel;
        },

        onPhaseChanged(data, turnId) {
            const phase = data.payload?.phase || data.phase;
            const label = data.payload?.label
                || data.label
                || this.labelForPhase(phase, phase);
            const state = this.ensureTurnState(turnId);
            if (!state) {
                return;
            }

            state.turnPhase = phase;
            state.turnLabel = label;

            // Update the most recent thinking entry description when we have a richer label.
            if (phase === 'awaiting_llm' && label && label !== this.phaseLabels.awaiting_llm) {
                for (let i = state.streamEntries.length - 1; i >= 0; i--) {
                    if (state.streamEntries[i].type === 'thinking') {
                        state.streamEntries[i].description = label.replace(/^(?:Thinking|Working|Awaiting model response)\s*—\s*/u, '');
                        break;
                    }
                }
            }
        },

        onThinkingStarted(data, turnId) {
            const payload = data?.payload || data || {};
            const description = payload.description || null;
            this.ensureTurnState(turnId)?.streamEntries.push({ type: 'thinking', active: true, description, thinkingContent: '' });
        },

        onThinkingDelta(data, turnId) {
            const payload = data?.payload || data || {};
            const delta = payload.delta || '';
            if (!delta) return;
            const state = this.ensureTurnState(turnId);
            if (!state) {
                return;
            }

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

        onToolStarted(data, turnId) {
            const state = this.ensureTurnState(turnId);
            if (!state) {
                return;
            }

            this.deactivateThinking(turnId);

            if (state.completedToolCount > 0 && !state.toolsCollapsed) {
                state.toolsCollapsed = true;
            }

            const payload = data.payload || data;
            const idx = state.streamEntries.length;
            const toolKey = payload.tool_call_index ?? idx;
            state.toolMap[toolKey] = idx;

            state.streamEntries.push({
                type: 'tool_use',
                tool: payload.tool || '',
                argsSummary: payload.args_summary || '',
                status: 'running',
                stdoutBuffer: '',
                collapsed: false,
            });
        },

        onToolFinished(data, turnId) {
            const payload = data.payload || data;
            const toolKey = payload.tool_call_index ?? -1;
            const state = this.ensureTurnState(turnId);
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
        },

        onToolStdoutDelta(data, turnId) {
            const payload = data.payload || data;
            const toolName = payload.tool || '';
            const delta = payload.delta || '';
            if (!delta) return;
            const state = this.ensureTurnState(turnId);
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

        onToolDenied(data, turnId) {
            const payload = data.payload || data;
            this.ensureTurnState(turnId)?.streamEntries.push({
                type: 'hook_action',
                stage: 'pre_tool_use',
                action: 'tool_denied',
                tool: payload.tool || '',
                reason: payload.reason || '',
                source: payload.source || 'hook',
            });
        },

        onOutputDelta(data, turnId) {
            const state = this.ensureTurnState(turnId);
            if (!state) {
                return;
            }

            this.deactivateThinking(turnId);

            const payload = data.payload || data;
            const text = payload.delta || payload.text || '';
            if (!text) return;

            state.deltaBuffer += text;

            const hasBoundary = /\n/.test(text);
            if (hasBoundary) {
                this.flushDeltaBuffer(turnId);

                return;
            }

            if (state.deltaFlushTimer) clearTimeout(state.deltaFlushTimer);
            state.deltaFlushTimer = setTimeout(() => this.flushDeltaBuffer(turnId), 80);
        },

        onTurnFailed(data, turnId) {
            const payload = data.payload || data;
            this.ensureTurnState(turnId)?.streamEntries.push({
                type: 'error',
                message: payload.message || this.turnFailedMessage,
            });
            this.finalizeTurnStream(turnId);
        },

        deactivateThinking(turnId = null) {
            const state = this.ensureTurnState(turnId || this.selectedTurnId);
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

        removeThinkingEntries(turnId = null) {
            const state = this.ensureTurnState(turnId || this.selectedTurnId);
            if (!state) {
                return;
            }

            state.streamEntries = state.streamEntries.filter(
                (entry) => entry.type !== 'thinking' || (entry.thinkingContent && entry.thinkingContent.trim()),
            );
        },

        stopStreaming() {
            this.pendingMessage = null;

            if (!this.selectedTurnId) {
                return;
            }

            const state = this.ensureTurnState(this.selectedTurnId);
            if (!state) {
                return;
            }

            state.turnLabel = this.stoppingLabel;
            this.$wire.cancelActiveTurn(this.selectedTurnId);
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
