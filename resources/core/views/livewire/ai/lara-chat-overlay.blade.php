<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\LaraChatOverlay $this */
?>
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
                                aria-label="{{ __('Delete session') }}"
                            >
                                <x-icon name="heroicon-o-trash" class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    @empty
                        <p class="text-sm text-muted py-4 text-center">{{ __('No sessions yet.') }}</p>
                    @endforelse
                </div>
            </aside>

            <section class="flex-1 min-h-0 flex flex-col"
                x-data="{ pendingMessage: null }"
                x-on:lara-response-ready.window="pendingMessage = null"
            >
                @if ($selectedSessionId)
                    <div
                        class="flex-1 min-h-0 overflow-y-auto px-4 py-3 space-y-3"
                        x-ref="laraScroll"
                        x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                        x-effect="$nextTick(() => $refs.laraScroll.scrollTop = $refs.laraScroll.scrollHeight)"
                    >
                        @forelse($messages as $message)
                            <div class="flex {{ $message->role === 'user' ? 'justify-end' : 'justify-start' }}">
                                @if ($message->role === 'assistant' && ($message->meta['orchestration']['status'] ?? null) !== null)
                                    {{-- Lara action message (navigation, guide, models, etc.) --}}
                                    <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm bg-accent/10 text-ink border border-accent/20">
                                        <div class="flex items-center gap-1.5 mb-0.5">
                                            <x-icon name="heroicon-o-bolt" class="w-3.5 h-3.5 text-accent" />
                                            <span class="text-[10px] font-semibold uppercase tracking-wider text-accent">{{ __('Action') }}</span>
                                        </div>
                                        <div class="whitespace-pre-wrap break-words">{{ $message->content }}</div>
                                        <div class="text-[10px] mt-1 text-muted tabular-nums">
                                            {{ $message->timestamp->format('H:i:s') }}
                                        </div>
                                    </div>
                                @else
                                    <div class="max-w-[80%] rounded-2xl px-3 py-2 text-sm
                                        {{ $message->role === 'user' ? 'bg-accent text-accent-on' : 'bg-surface-subtle text-ink' }}"
                                    >
                                        <div class="whitespace-pre-wrap break-words">{{ $message->content }}</div>
                                        <div class="text-[10px] mt-1 {{ $message->role === 'user' ? 'text-accent-on/70' : 'text-muted' }} tabular-nums">
                                            {{ $message->timestamp->format('H:i:s') }}
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div x-show="!pendingMessage" class="h-full flex items-center justify-center">
                                <p class="text-sm text-muted">{{ __('Send a message to start chatting with Lara.') }}</p>
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

                        {{-- Loading dots: shown while waiting for Livewire response --}}
                        <div x-show="pendingMessage" x-cloak class="flex justify-start">
                            <div class="bg-surface-subtle rounded-2xl px-3 py-2">
                                <div class="flex gap-1">
                                    <span class="w-2 h-2 bg-muted/50 rounded-full animate-pulse"></span>
                                    <span class="w-2 h-2 bg-muted/50 rounded-full animate-pulse" style="animation-delay: 150ms"></span>
                                    <span class="w-2 h-2 bg-muted/50 rounded-full animate-pulse" style="animation-delay: 300ms"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-border-default px-4 py-3">
                        <form
                            wire:submit="sendMessage"
                            x-on:submit="pendingMessage = $refs.laraComposer.value; $refs.laraComposer.value = ''; $nextTick(() => { $refs.laraScroll.scrollTop = $refs.laraScroll.scrollHeight })"
                            class="flex items-end gap-2"
                        >
                            <div class="flex-1 min-w-0">
                                    <x-ui.input
                                        x-ref="laraComposer"
                                        wire:model="messageInput"
                                        placeholder="{{ __('Ask Lara about BLB, use /go <target>, /models <filter>, /guide <topic>, or /delegate <task>...') }}"
                                        autocomplete="off"
                                        x-bind:disabled="!!pendingMessage"
                                    />
                            </div>
                            <x-ui.button type="submit" variant="primary" x-bind:disabled="!!pendingMessage">
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
