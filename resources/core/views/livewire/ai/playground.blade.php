<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Modules\Core\AI\Livewire\Playground $this */
?>
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
                        type="button"
                        class="ml-auto px-2 py-2 text-muted hover:text-ink transition-colors"
                        title="{{ __('LLM Configuration') }}"
                        aria-label="{{ __('LLM Configuration') }}"
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
                                    <button type="button" @click="open = !open" class="flex items-center gap-1 text-muted hover:text-ink transition-colors w-full text-left">
                                        <span class="text-[10px]" x-text="open ? '▾' : '▸'" aria-hidden="true"></span>
                                        <span class="text-muted">{{ __('Fallback Attempts') }} ({{ count($lastRunMeta['fallback_attempts']) }})</span>
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
                <button wire:click="$set('showLlmConfig', false)" type="button" class="text-muted hover:text-ink" aria-label="{{ __('Close') }}">
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
                                    type="button"
                                    @if($index === 0) disabled @endif
                                    title="{{ __('Move Up') }}"
                                    aria-label="{{ __('Move Up') }}"
                                >
                                    <x-icon name="heroicon-m-chevron-down" class="w-4 h-4 rotate-180" />
                                </button>
                                <button
                                    wire:click="moveLlmModel({{ $index }}, 'down')"
                                    class="text-muted hover:text-ink p-1 rounded disabled:opacity-50 disabled:cursor-not-allowed"
                                    type="button"
                                    @if($index === count($llmModels) - 1) disabled @endif
                                    title="{{ __('Move Down') }}"
                                    aria-label="{{ __('Move Down') }}"
                                >
                                    <x-icon name="heroicon-m-chevron-down" class="w-4 h-4" />
                                </button>
                                <button
                                    wire:click="removeLlmModel({{ $index }})"
                                    class="text-muted hover:text-ink p-1 rounded disabled:opacity-50 disabled:cursor-not-allowed"
                                    type="button"
                                    @if(count($llmModels) <= 1) disabled @endif
                                    title="{{ __('Remove') }}"
                                    aria-label="{{ __('Remove LLM model') }}"
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
