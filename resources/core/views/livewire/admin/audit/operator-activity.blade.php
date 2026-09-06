<?php

use App\Base\Audit\Livewire\AuditLog\OperatorActivity;

/** @var OperatorActivity $this */
?>

<div>
    <x-slot name="title">{{ __('Audit Activity') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Audit Activity')"
            :subtitle="__('Actor, operation, and time for this tenant — contents stay off the page')"
        />

        <x-ui.card>
            <div class="mb-3 grid grid-cols-1 gap-2 lg:grid-cols-[minmax(14rem,1fr)_minmax(10rem,12rem)_minmax(10rem,12rem)_auto_auto]">
                <x-ui.search-input
                    id="operator-audit-search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search actor, operation, or URL...') }}"
                />

                <x-ui.input
                    id="operator-audit-actor"
                    type="text"
                    wire:model.live.debounce.300ms="filterActor"
                    placeholder="{{ __('Actor') }}"
                    aria-label="{{ __('Filter by actor') }}"
                />

                <x-ui.input
                    id="operator-audit-operation"
                    type="text"
                    wire:model.live.debounce.300ms="filterOperation"
                    placeholder="{{ __('Operation (exact event)') }}"
                    aria-label="{{ __('Filter by operation') }}"
                />

                <x-ui.input
                    id="operator-audit-from"
                    type="date"
                    wire:model.live="filterFrom"
                    aria-label="{{ __('From date') }}"
                />

                <x-ui.input
                    id="operator-audit-to"
                    type="date"
                    wire:model.live="filterTo"
                    aria-label="{{ __('To date') }}"
                />
            </div>

            <x-ui.table container="flush" :caption="__('Tenant audit activity')">
                <x-slot name="head">
                    <tr>
                        <x-ui.sortable-th
                            column="occurred_at"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('occurred_at')"
                            :label="__('Occurred')"
                        />
                        <x-ui.sortable-th
                            column="actor_name"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('actor_name')"
                            :label="__('Actor')"
                        />
                        <x-ui.sortable-th
                            column="event"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('event')"
                            :label="__('Operation')"
                        />
                        <x-ui.th>{{ __('Scope') }}</x-ui.th>
                    </tr>
                </x-slot>

                @forelse ($rows as $row)
                    <tr wire:key="operator-audit-{{ $row->id }}">
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">
                            <x-ui.datetime :value="$row->occurred_at" />
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm">
                            <div class="text-ink">{{ $row->actor_name ?? ($row->actor_type.'#'.$row->actor_id) }}</div>
                            @if ($row->actor_role)
                                <div class="mt-0.5 font-mono text-xs text-muted">{{ $row->actor_role }}</div>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">
                            {{ $row->event }}
                        </td>
                        <td class="px-table-cell-x py-table-cell-y max-w-[20rem] truncate text-sm text-muted" title="{{ $row->url ?? '' }}">
                            {{ $row->url ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-table-cell-x py-8 text-center text-sm text-muted">
                            {{ __('No audit activity matches the current filters for this tenant.') }}
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>

            <div class="mt-2">
                {{ $rows->links(data: ['scrollTo' => false]) }}
            </div>
        </x-ui.card>
    </div>
</div>
