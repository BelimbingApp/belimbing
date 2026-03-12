<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\LaraChatOverlay $this */
?>
<div
    class="h-full flex flex-col"
    x-data="{
        sessionsOpen: false,
        sessionWidth: parseInt(localStorage.getItem('dw-chat-session-width')) || 224,
        _sessionDragging: false,
        SESSION_MIN: 160,
        SESSION_MAX: 320,

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
                localStorage.setItem('dw-chat-session-width', this.sessionWidth);
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        }
    }"
    x-init="$nextTick(() => $refs.dwComposer?.focus())"
    @lara-focus-composer.window="$nextTick(() => $refs.dwComposer?.focus())"
    @lara-chat-opened.window="if ($event.detail?.prompt) { $wire.set('messageInput', $event.detail.prompt); $nextTick(() => $refs.dwComposer?.focus()); }"
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
            <x-ai.dw-identity
                :name="$dwIdentity['name']"
                :role="$dwIdentity['role']"
                :icon="$dwIdentity['icon']"
                :show-role="false"
            />
        </div>

        <div class="flex items-center gap-1">
            {{-- Keyboard shortcuts cheatsheet --}}
            <div x-data="{ shortcutsOpen: false, _mod: navigator.platform?.includes('Mac') ? '⌘' : 'Ctrl' }" class="relative">
                <x-ui.help
                    size="sm"
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
                x-on:click="$dispatch('toggle-lara-chat-mode')"
                class="hidden sm:inline-flex text-muted hover:text-ink transition-colors p-0.5"
                title="{{ __('Toggle docked mode') }}"
                aria-label="{{ __('Toggle docked mode') }}"
            >
                <x-icon name="heroicon-o-arrows-right-left" class="w-4 h-4" />
            </button>
            @if ($isLara)
                <a
                    href="{{ route('admin.setup.lara') }}"
                    wire:navigate
                    class="text-muted hover:text-ink transition-colors p-0.5"
                    title="{{ __('Settings') }}"
                    aria-label="{{ __('Settings') }}"
                >
                    <x-icon name="heroicon-o-cog-6-tooth" class="w-4 h-4" />
                </a>
            @endif
            <button
                type="button"
                x-on:click="$dispatch('close-lara-chat')"
                class="text-muted hover:text-ink transition-colors p-0.5"
                title="{{ __('Close chat') }}"
                aria-label="{{ __('Close chat') }}"
            >
                <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
            </button>
        </div>
    </div>

    @if (! $dwExists)
        <div class="p-4">
            <x-ui.alert variant="warning">
                {{ __(':name has not been provisioned yet.', ['name' => $dwIdentity['name']]) }}
                @if ($isLara)
                    <a href="{{ route('admin.setup.lara') }}" wire:navigate class="text-accent hover:underline">
                        {{ __('Set up :name', ['name' => $dwIdentity['name']]) }}
                    </a>
                @endif
            </x-ui.alert>
        </div>
    @elseif (! $dwActivated)
        <div class="p-4">
            <x-ui.alert variant="info">
                {{ __(':name is not activated yet. Configure an AI provider to start chatting.', ['name' => $dwIdentity['name']]) }}
                @if ($isLara)
                    <a href="{{ route('admin.setup.lara') }}" wire:navigate class="text-accent hover:underline">
                        {{ __('Activate :name', ['name' => $dwIdentity['name']]) }}
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
                        <div class="group flex items-start gap-1" wire:key="dw-session-{{ $session->id }}">
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
                                <button
                                    wire:click="selectSession('{{ $session->id }}')"
                                    class="flex-1 text-left px-2 py-1.5 rounded-lg text-sm transition-colors
                                        {{ $selectedSessionId === $session->id ? 'bg-surface-subtle text-ink' : 'text-muted hover:bg-surface-subtle/60 hover:text-ink' }}"
                                >
                                    <div class="truncate font-medium">{{ $session->title ?? __('Untitled') }}</div>
                                    <div class="text-xs text-muted tabular-nums">{{ $session->lastActivityAt->format('M j, H:i') }}</div>
                                </button>
                                <div class="flex items-center mt-1">
                                    <button
                                        type="button"
                                        wire:click="startEditingTitle('{{ $session->id }}')"
                                        class="text-muted hover:text-ink p-1"
                                        title="{{ __('Edit title') }}"
                                        aria-label="{{ __('Edit title') }}"
                                    >
                                        <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="deleteSession('{{ $session->id }}')"
                                        class="text-muted hover:text-ink p-1"
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
            <section class="flex-1 min-h-0 flex flex-col"
                x-data="{
                    pendingMessage: null,
                    streamingContent: '',
                    streamingStatus: null,
                    _eventSource: null,
                }"
                x-on:lara-response-ready.window="pendingMessage = null; streamingContent = ''; streamingStatus = null"
            >
                <div
                    class="flex-1 min-h-0 overflow-y-auto px-4 py-3 space-y-3"
                    x-ref="dwScroll"
                    x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                    x-effect="$nextTick(() => $refs.dwScroll.scrollTop = $refs.dwScroll.scrollHeight)"
                >
                    @forelse($messages as $message)
                        <div class="flex {{ $message->role === 'user' ? 'justify-end' : 'justify-start' }}">
                            @if ($message->role === 'assistant' && ($message->meta['orchestration']['status'] ?? null) !== null)
                                {{-- Action message (navigation, guide, models, etc.) --}}
                                <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm bg-accent/10 text-ink border border-accent/20">
                                    <div class="flex items-center gap-1.5 mb-0.5">
                                        <x-icon name="heroicon-o-bolt" class="w-3.5 h-3.5 text-accent" />
                                        <span class="text-[10px] font-semibold uppercase tracking-wider text-accent">{{ __('Action') }}</span>
                                    </div>
                                    <div class="dw-prose">{!! $markdown->render($message->content) !!}</div>
                                    <div class="text-[10px] mt-1 text-muted tabular-nums">
                                        {{ $message->timestamp->format('H:i:s') }}
                                    </div>
                                </div>
                            @else
                                <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm
                                    {{ $message->role === 'user' ? 'bg-accent text-accent-on' : 'bg-surface-subtle text-ink' }}"
                                >
                                    @if ($message->role === 'assistant')
                                        <div class="dw-prose">{!! $markdown->render($message->content) !!}</div>
                                    @else
                                        <div class="whitespace-pre-wrap break-words">{{ $message->content }}</div>
                                    @endif
                                    <div class="text-[10px] mt-1 {{ $message->role === 'user' ? 'text-accent-on/70' : 'text-muted' }} tabular-nums">
                                        {{ $message->timestamp->format('H:i:s') }}
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div x-show="!pendingMessage" class="h-full flex flex-col items-center justify-center gap-4">
                            <p class="text-sm text-muted">{{ __('Send a message to start chatting with :name.', ['name' => $dwIdentity['name']]) }}</p>
                            @if (count($quickActions) > 0)
                                <div class="flex flex-wrap justify-center gap-2 max-w-sm">
                                    @foreach ($quickActions as $action)
                                        <button
                                            type="button"
                                            x-on:click="$dispatch('open-lara-chat', { prompt: '{{ str_replace("'", "\\'", $action['prompt']) }}' })"
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

                    {{-- Streaming response bubble --}}
                    <template x-if="streamingContent">
                        <div class="flex justify-start">
                            <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm bg-surface-subtle text-ink">
                                <div class="dw-prose whitespace-pre-wrap break-words" x-text="streamingContent"></div>
                            </div>
                        </div>
                    </template>

                    {{-- Tool status indicator --}}
                    <div x-show="streamingStatus && !streamingContent" x-cloak class="flex justify-start">
                        <div class="bg-surface-subtle rounded-2xl px-3 py-2 text-xs text-muted flex items-center gap-1.5">
                            <span class="w-2 h-2 bg-accent rounded-full animate-pulse"></span>
                            <span x-text="streamingStatus"></span>
                        </div>
                    </div>

                    {{-- Loading dots: shown while waiting for non-streaming response --}}
                    <div x-show="pendingMessage && !streamingContent && !streamingStatus" x-cloak class="flex justify-start">
                        <div class="bg-surface-subtle rounded-2xl px-3 py-2">
                            <div class="flex gap-1">
                                <span class="w-2 h-2 bg-muted/50 rounded-full animate-pulse"></span>
                                <span class="w-2 h-2 bg-muted/50 rounded-full animate-pulse" style="animation-delay: 150ms"></span>
                                <span class="w-2 h-2 bg-muted/50 rounded-full animate-pulse" style="animation-delay: 300ms"></span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Composer --}}
                <div class="border-t border-border-default px-4 py-3 space-y-2">
                    {{-- Model picker (authz-gated) --}}
                    @if ($canSelectModel && count($availableModels) > 0)
                        <div x-data="{ modelPickerOpen: false }" class="relative">
                            <button
                                type="button"
                                x-on:click="modelPickerOpen = !modelPickerOpen"
                                class="inline-flex items-center gap-1 text-[11px] text-muted hover:text-ink transition-colors"
                            >
                                <x-icon name="heroicon-o-cpu-chip" class="w-3 h-3" />
                                <span class="truncate max-w-[12rem]">{{ $currentModel }}</span>
                                <x-icon name="heroicon-o-chevron-up" class="w-3 h-3" />
                            </button>

                            <div
                                x-show="modelPickerOpen"
                                x-on:click.outside="modelPickerOpen = false"
                                x-transition.opacity.duration.100ms
                                x-cloak
                                class="absolute bottom-full left-0 mb-1 w-72 max-h-48 overflow-y-auto bg-surface-card border border-border-default rounded-xl shadow-lg z-20"
                            >
                                @foreach ($availableModels as $model)
                                    <button
                                        type="button"
                                        wire:click="selectModel('{{ $model['id'] }}')"
                                        x-on:click="modelPickerOpen = false"
                                        class="w-full text-left px-3 py-1.5 text-sm hover:bg-surface-subtle transition-colors flex items-center justify-between gap-2
                                            {{ ($selectedModel ?? '') === $model['id'] ? 'text-accent font-medium' : 'text-ink' }}"
                                    >
                                        <span class="truncate">{{ $model['label'] }}</span>
                                        <span class="text-[10px] text-muted shrink-0">{{ $model['provider'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="inline-flex items-center gap-1 text-[11px] text-muted">
                            <x-icon name="heroicon-o-cpu-chip" class="w-3 h-3" />
                            <span class="truncate max-w-[12rem]">{{ $currentModel }}</span>
                        </div>
                    @endif

                    <form
                        wire:submit="sendMessage"
                        x-data="dwComposer()"
                        x-on:submit="onSubmit($refs.dwComposer, $refs.dwScroll)"
                        class="space-y-2"
                    >
                        {{-- Attachment preview pills --}}
                        @if ($canAttachFiles && count($this->attachments) > 0)
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($this->attachments as $index => $file)
                                    <div class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-surface-subtle border border-border-default text-xs text-ink" wire:key="att-{{ $index }}">
                                        @if ($file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile && str_starts_with($file->getMimeType() ?? '', 'image/'))
                                            <x-icon name="heroicon-o-photo" class="w-3.5 h-3.5 text-muted shrink-0" />
                                        @else
                                            <x-icon name="heroicon-o-document" class="w-3.5 h-3.5 text-muted shrink-0" />
                                        @endif
                                        <span class="truncate max-w-[8rem]">{{ $file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile ? $file->getClientOriginalName() : __('File') }}</span>
                                        <button
                                            type="button"
                                            wire:click="removeAttachment({{ $index }})"
                                            class="text-muted hover:text-ink transition-colors shrink-0"
                                            aria-label="{{ __('Remove attachment') }}"
                                        >
                                            <x-icon name="heroicon-o-x-mark" class="w-3 h-3" />
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <div class="flex items-end gap-2">
                            @if ($canAttachFiles)
                                <label class="shrink-0 mb-px cursor-pointer text-muted hover:text-ink transition-colors p-1" title="{{ __('Attach file') }}" aria-label="{{ __('Attach file') }}">
                                    <x-icon name="heroicon-o-paper-clip" class="w-4 h-4" />
                                    <input
                                        type="file"
                                        wire:model="attachments"
                                        multiple
                                        accept="image/*,.pdf,.txt,.csv,.md,.json"
                                        class="hidden"
                                    />
                                </label>
                            @endif
                            <div class="flex-1 min-w-0">
                                <textarea
                                    x-ref="dwComposer"
                                    wire:model="messageInput"
                                    x-on:keydown="onKeydown($event)"
                                    x-on:input="autoResize($el)"
                                    x-init="autoResize($el)"
                                    placeholder="{{ __('Message :name...', ['name' => $dwIdentity['name']]) }}"
                                    autocomplete="off"
                                    x-bind:disabled="!!pendingMessage"
                                    rows="1"
                                    class="w-full px-input-x py-input-y text-sm border rounded-2xl transition-colors border-border-input bg-surface-card text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent disabled:opacity-50 disabled:cursor-not-allowed resize-none overflow-hidden"
                                ></textarea>
                            </div>
                            <x-ui.button type="submit" variant="primary" x-bind:disabled="!!pendingMessage" class="shrink-0 mb-px">
                                <x-icon name="heroicon-o-paper-airplane" class="w-4 h-4" />
                            </x-ui.button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    @endif
</div>

@script
<script>
    Alpine.data('dwComposer', () => ({
        maxRows: 6,

        onKeydown(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                this.$el.closest('form').requestSubmit();
            }
        },

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

            this.streamingContent = '';
            this.streamingStatus = null;
            const source = new EventSource(url);
            this._eventSource = source;

            source.addEventListener('status', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    if (data.phase === 'thinking') {
                        this.streamingStatus = '{{ __('Thinking...') }}';
                    } else if (data.phase === 'tool_started') {
                        this.streamingStatus = '🔧 ' + (data.tool || '{{ __('Running tool...') }}');
                    } else if (data.phase === 'tool_finished') {
                        this.streamingStatus = null;
                    }
                } catch {}
                this.scrollToBottom(scrollContainer);
            });

            source.addEventListener('delta', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    if (data.text) {
                        this.streamingContent += data.text;
                        this.streamingStatus = null;
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
                            this.streamingContent = '⚠ ' + data.message;
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
                if (!this.streamingContent) {
                    this.$wire.sendMessage();
                } else {
                    this.$wire.finalizeStreamingRun();
                }
            };
        },

        scrollToBottom(container) {
            this.$nextTick(() => {
                if (container) container.scrollTop = container.scrollHeight;
            });
        },

        autoResize(el) {
            el.style.height = 'auto';
            const lineHeight = parseInt(getComputedStyle(el).lineHeight) || 20;
            const maxHeight = lineHeight * this.maxRows;
            const newHeight = Math.min(el.scrollHeight, maxHeight);
            el.style.height = newHeight + 'px';
            el.style.overflowY = el.scrollHeight > maxHeight ? 'auto' : 'hidden';
        },
    }));
</script>
@endscript
