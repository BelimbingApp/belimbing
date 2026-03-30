<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Base\Audit\Livewire\AuditLog\Actions $this */
?>

<div x-data="{ payloadModal: false, payloadJson: '' }">
    <x-slot name="title">{{ __('Actions') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Actions')" :subtitle="__('HTTP, auth, console, and queue action trail')" />

        <x-ui.card>
            <div class="mb-2 flex items-center gap-3">
                <div class="flex-1">
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search by event name or actor...') }}"
                    />
                </div>
                <x-ui.select id="filter-actor-type" wire:model.live="filterActorType">
                    <option value="">{{ __('All Actor Types') }}</option>
                    <option value="human_user">{{ __('Human User') }}</option>
                    <option value="agent">{{ __('Agent') }}</option>
                    <option value="console">{{ __('Console') }}</option>
                    <option value="scheduler">{{ __('Scheduler') }}</option>
                    <option value="queue">{{ __('Queue') }}</option>
                </x-ui.select>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Occurred At') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actor') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Role') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Event') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('IP') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('URL') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Payload') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-center text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Retain') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($actions as $action)
                            <tr
                                wire:key="action-{{ $action->id }}"
                                class="hover:bg-surface-subtle/50 transition-colors cursor-pointer"
                                @click="payloadJson = {{ \Illuminate\Support\Js::from($action->payload) }} ? JSON.stringify({{ \Illuminate\Support\Js::from($action->payload) }}, null, 2) : ''; payloadModal = true"
                            >
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"><x-ui.datetime :value="$action->occurred_at" /></td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    {{ $action->actor_name ?? $action->actor_type . '#' . $action->actor_id }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-xs text-muted">{{ $action->actor_role ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">{{ $action->event }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $action->ip_address ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted max-w-[200px] truncate" title="{{ $action->url }}">{{ $action->url ? Str::limit($action->url, 40) : '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-xs text-muted font-mono max-w-[250px] truncate">
                                    @if($action->payload)
                                        {{ Str::limit(json_encode($action->payload), 60) }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-center">
                                    <button
                                        wire:click.stop="toggleRetain({{ $action->id }})"
                                        class="transition-colors {{ $action->is_retained ? 'text-accent hover:text-accent-hover' : 'text-muted/40 hover:text-muted' }}"
                                        title="{{ $action->is_retained ? __('Remove retention') : __('Retain this entry') }}"
                                    >
                                        @if($action->is_retained)
                                            <x-icon name="heroicon-s-bookmark" class="size-4" />
                                        @else
                                            <x-icon name="heroicon-o-bookmark" class="size-4" />
                                        @endif
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No action logs found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $actions->links(data: ['scrollTo' => false]) }}
            </div>
        </x-ui.card>
    </div>

    {{-- Payload detail modal --}}
    <div
        x-show="payloadModal"
        x-cloak
        @keydown.escape.window="payloadModal = false"
        class="fixed inset-0 z-50 overflow-y-auto"
        style="display: none;"
    >
        <div
            x-show="payloadModal"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @click="payloadModal = false"
            class="fixed inset-0 bg-black/50"
        ></div>

        <div class="flex min-h-full items-center justify-center p-4">
            <div
                x-show="payloadModal"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 scale-100"
                x-transition:leave-end="opacity-0 scale-95"
                @click.stop
                class="relative bg-surface-card border border-border-default rounded-2xl shadow-xl w-full max-w-lg p-card-inner"
            >
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-medium text-ink">{{ __('Payload') }}</h3>
                    <button @click="payloadModal = false" class="text-muted hover:text-ink transition-colors" aria-label="{{ __('Close') }}">
                        <x-icon name="heroicon-o-x-mark" class="size-5" />
                    </button>
                </div>
                <template x-if="payloadJson">
                    <pre class="bg-surface-subtle/50 border border-border-default rounded-lg p-3 text-xs font-mono text-ink overflow-x-auto max-h-[60vh] overflow-y-auto" x-text="payloadJson"></pre>
                </template>
                <template x-if="!payloadJson">
                    <p class="text-sm text-muted italic">{{ __('No payload recorded.') }}</p>
                </template>
            </div>
        </div>
    </div>
</div>
