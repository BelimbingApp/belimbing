<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Base\Database\Livewire\Queries\Show $this */
?>

<div>
    <x-slot name="title">{{ $editName }}</x-slot>

    <div class="space-y-section-gap">

        {{-- Page Header --}}
        <x-ui.page-header
            :pinnable="(! $isNew && $this->query) ? [
                'label' => $this->query->name,
                'url' => request()->url(),
                'icon' => $this->query->icon ?? 'heroicon-o-circle-stack',
            ] : null"
        >
            <x-slot name="title">
                <span x-data="{ editing: false }">
                    <span
                        x-show="!editing"
                        x-text="$wire.editName"
                        x-on:click="editing = true; $nextTick(() => { $refs.titleInput.focus(); $refs.titleInput.select(); })"
                        class="cursor-text"
                    ></span>
                    <input
                        x-show="editing"
                        x-cloak
                        x-ref="titleInput"
                        x-on:blur="editing = false"
                        x-on:keydown.enter.prevent="editing = false"
                        x-on:keydown.escape="editing = false"
                        wire:model="editName"
                        type="text"
                        class="bg-transparent border-0 border-b border-accent focus:ring-0 px-0 py-0 outline-none transition-colors min-w-[200px]"
                        :size="Math.max(($wire.editName || '').length, 10)"
                        aria-label="{{ __('Query name') }}"
                    />
                </span>
            </x-slot>
            <x-slot name="actions">
                <x-ui.button variant="ghost" size="sm" href="{{ route('admin.system.database-queries.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
                </x-ui.button>
                @if(! $isNew)
                    {{-- Share --}}
                    <div x-data="{ open: false }" class="relative">
                        <x-ui.button
                            variant="ghost"
                            size="sm"
                            x-on:click="open = !open; if(open) $nextTick(() => $refs.shareSearchInput?.focus())"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z" />
                            </svg>
                            {{ __('Share') }}
                        </x-ui.button>

                        <div
                            x-show="open"
                            x-on:click.outside="open = false; $wire.set('shareSearch', ''); $wire.set('shareSuccess', '')"
                            x-on:keydown.escape.window="open = false"
                            x-transition.opacity.duration.100ms
                            x-cloak
                            class="absolute right-0 top-full mt-1 w-80 bg-surface-card border border-border-default rounded-xl shadow-lg z-30 p-3"
                        >
                            <div class="text-xs font-semibold text-muted uppercase tracking-wider mb-2">{{ __('Share with') }}</div>

                            @if($shareSuccess)
                                <div class="flex items-center gap-1.5 text-sm text-green-600 dark:text-green-400 mb-2">
                                    <x-icon name="heroicon-o-check-circle" class="w-4 h-4 shrink-0" />
                                    {{ $shareSuccess }}
                                </div>
                            @endif

                            <input
                                x-ref="shareSearchInput"
                                wire:model.live.debounce.300ms="shareSearch"
                                type="text"
                                placeholder="{{ __('Search by name or email…') }}"
                                class="w-full rounded-lg border border-border-input bg-surface-card text-sm text-ink px-2.5 py-1.5 focus:border-accent focus:ring-0 transition-colors mb-2"
                                aria-label="{{ __('Search users') }}"
                            />

                            <div class="max-h-48 overflow-y-auto space-y-0.5">
                                @forelse($this->shareableUsers() as $user)
                                    <button
                                        type="button"
                                        wire:click="shareWith({{ $user['id'] }})"
                                        class="w-full text-left px-2.5 py-1.5 rounded-lg hover:bg-surface-subtle transition-colors flex items-center justify-between gap-2"
                                    >
                                        <div class="min-w-0">
                                            <div class="text-sm text-ink truncate">{{ $user['name'] }}</div>
                                            <div class="text-[11px] text-muted truncate">{{ $user['email'] }}</div>
                                        </div>
                                        <x-icon name="heroicon-o-paper-airplane" class="w-3.5 h-3.5 text-muted shrink-0" />
                                    </button>
                                @empty
                                    <div class="text-sm text-muted text-center py-3">
                                        {{ $shareSearch ? __('No users found.') : __('Type to search for users.') }}
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <x-ui.button
                        variant="danger-ghost"
                        size="sm"
                        wire:click="delete"
                        wire:confirm="{{ __('Are you sure you want to delete this query?') }}"
                    >
                        <x-icon name="heroicon-o-trash" class="w-4 h-4" />
                        {{ __('Delete') }}
                    </x-ui.button>
                @else
                    <x-ui.button
                        variant="danger-ghost"
                        size="sm"
                        wire:click="delete"
                    >
                        <x-icon name="heroicon-o-x-mark" class="w-4 h-4" />
                        {{ __('Discard') }}
                    </x-ui.button>
                @endif
                <x-ui.button variant="primary" wire:click="save">
                    @if($this->isDirty)
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-accent-on opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-accent-on"></span>
                        </span>
                    @endif
                    <x-icon name="heroicon-o-save" class="w-4 h-4" />
                    {{ __('Save') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        {{-- Inline-editable description --}}
        <input
            type="text"
            wire:model="editDescription"
            placeholder="{{ __('Add a description...') }}"
            class="w-full bg-transparent border-0 border-b border-transparent hover:border-border-input focus:border-accent focus:ring-0 text-sm text-muted px-0 py-0.5 transition-colors placeholder:text-muted/50"
            aria-label="{{ __('Query description') }}"
        />

        {{-- Prompt (hero input) --}}
        <x-ui.card>
            <div class="flex items-center gap-2 mb-2">
                <x-icon name="heroicon-o-chat-bubble-left-ellipsis" class="w-4 h-4 text-muted shrink-0" />
                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Prompt') }}</span>
            </div>
            <x-ui.textarea
                wire:model="editPrompt"
                rows="3"
                placeholder="{{ __('Describe the data you would like to view...') }}"
                class="text-sm"
                aria-label="{{ __('Natural language prompt') }}"
            />

            @if(count($availableModels) > 0)
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <x-ai.model-selector
                        :models="$availableModels"
                        wire:model.live="selectedModelId"
                        class="max-w-xs"
                        aria-label="{{ __('AI model') }}"
                    />
                    <x-ui.button
                        variant="primary"
                        size="sm"
                        wire:click="generateSql"
                        wire:loading.attr="disabled"
                        wire:target="generateSql"
                        :disabled="$isGenerating"
                        class="whitespace-nowrap"
                    >
                        <x-icon name="heroicon-o-paper-airplane" class="w-4 h-4" wire:loading.remove wire:target="generateSql" />
                        <x-icon name="heroicon-o-arrow-path" class="w-4 h-4 animate-spin" wire:loading wire:target="generateSql" />
                        {{ __('Generate') }}
                    </x-ui.button>
                </div>

                @if($aiError)
                    <x-ui.alert variant="danger" class="mt-2">{{ $aiError }}</x-ui.alert>
                @endif
            @endif
        </x-ui.card>

        {{-- SQL Query (click-to-edit) --}}
        <div x-data="{ editingSql: false }">
            <div class="flex items-center gap-1.5 mb-1">
                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('SQL Query') }}</span>
                <button
                    type="button"
                    x-on:click="editingSql = true; $nextTick(() => $refs.sqlInput.focus())"
                    class="text-muted hover:text-ink transition-colors"
                    title="{{ __('Edit SQL') }}"
                    aria-label="{{ __('Edit SQL') }}"
                >
                    <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5" />
                </button>
            </div>
            <span
                x-show="!editingSql"
                x-on:click="editingSql = true; $nextTick(() => $refs.sqlInput.focus())"
                x-text="$wire.editSql || '{{ __('No SQL yet — generate from prompt or click to write') }}'"
                class="font-mono text-xs cursor-text"
                :class="$wire.editSql ? 'text-ink' : 'text-muted'"
            ></span>
            <textarea
                x-ref="sqlInput"
                x-show="editingSql"
                x-cloak
                x-on:blur="editingSql = false"
                x-on:keydown.escape="editingSql = false"
                wire:model="editSql"
                rows="6"
                class="w-full font-mono text-xs border rounded-lg border-border-input bg-surface-card text-ink px-input-x py-input-y focus:border-accent focus:ring-0 transition-colors"
                aria-label="{{ __('SQL Query') }}"
            ></textarea>
        </div>

        {{-- Action row: Run --}}
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.button variant="primary" size="sm" wire:click="runQuery">
                <x-icon name="heroicon-o-play" class="w-4 h-4" />
                {{ __('Run') }}
            </x-ui.button>
        </div>

        {{-- Query Error --}}
        @if($error)
            <x-ui.alert variant="danger">{{ $error }}</x-ui.alert>
        @endif

        {{-- Results Table --}}
        <x-ui.card>
            <div class="mb-2 flex items-center justify-between gap-4">
                <span class="text-xs text-muted whitespace-nowrap tabular-nums">
                    {{ trans_choice(':count column|:count columns', count($columns), ['count' => count($columns)]) }}
                    · {{ trans_choice(':count row|:count rows', $total, ['count' => number_format($total)]) }}
                </span>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            @foreach($columns as $col)
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">
                                    {{ $col }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($rows as $index => $row)
                            <tr wire:key="row-{{ $index }}" class="hover:bg-surface-subtle/50 transition-colors">
                                @foreach($columns as $col)
                                    @php
                                        $value = $row[$col] ?? null;
                                        $isLong = $value !== null && mb_strlen((string) $value) > 120;
                                    @endphp
                                    <td
                                        class="px-table-cell-x py-table-cell-y font-mono text-sm whitespace-nowrap {{ $value === null ? 'text-muted' : 'text-ink' }}"
                                        @if($isLong) title="{{ Str::limit((string) $value, 500) }}" @endif
                                    >
                                        @if($value === null)
                                            —
                                        @elseif($isLong)
                                            {{ Str::limit((string) $value, 120) }}
                                        @else
                                            {{ $value }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($columns) }}" class="px-table-cell-x py-8 text-center text-sm text-muted">
                                    {{ __('No rows returned.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Manual Pagination --}}
            @if($lastPage > 1)
                <div class="mt-2 flex items-center justify-between text-sm text-muted">
                    <span class="tabular-nums">
                        {{ __('Showing :from to :to of :total results', [
                            'from' => number_format(($currentPage - 1) * $perPage + 1),
                            'to' => number_format(min($currentPage * $perPage, $total)),
                            'total' => number_format($total),
                        ]) }}
                    </span>
                    <div class="flex items-center gap-2">
                        <x-ui.button
                            variant="ghost"
                            size="sm"
                            wire:click="previousPage"
                            :disabled="$currentPage <= 1"
                        >
                            <x-icon name="heroicon-o-chevron-left" class="w-4 h-4" />
                            {{ __('Previous') }}
                        </x-ui.button>
                        <span class="tabular-nums text-xs">
                            {{ $currentPage }} / {{ $lastPage }}
                        </span>
                        <x-ui.button
                            variant="ghost"
                            size="sm"
                            wire:click="nextPage"
                            :disabled="$currentPage >= $lastPage"
                        >
                            {{ __('Next') }}
                            <x-icon name="heroicon-m-chevron-right" class="w-4 h-4" />
                        </x-ui.button>
                    </div>
                </div>
            @endif
        </x-ui.card>
    </div>
</div>
