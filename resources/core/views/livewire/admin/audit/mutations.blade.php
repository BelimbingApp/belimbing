<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Base\Audit\Livewire\AuditLog\Mutations $this */
?>

<div>
    <x-slot name="title">{{ __('Data Mutations') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Data Mutations')" :subtitle="__('Audit trail for all data changes')" />

        <x-ui.card>
            <div class="mb-2 flex items-center gap-3">
                <div class="flex-1">
                    <x-ui.search-input
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search by actor name, model type, or event...') }}"
                    />
                </div>
                <x-ui.select id="filter-event" wire:model.live="filterEvent">
                    <option value="">{{ __('All Events') }}</option>
                    <option value="created">{{ __('Created') }}</option>
                    <option value="updated">{{ __('Updated') }}</option>
                    <option value="deleted">{{ __('Deleted') }}</option>
                </x-ui.select>
            </div>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="occurred_at"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('occurred_at')"
                                :label="__('Occurred At')"
                            />
                            <x-ui.sortable-th
                                column="actor_name"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('actor_name')"
                                :label="__('Actor')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Role') }}</th>
                            <x-ui.sortable-th
                                column="event"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('event')"
                                :label="__('Event')"
                            />
                            <x-ui.sortable-th
                                column="auditable_type"
                                :sort-by="$sortBy"
                                :sort-dir="$sortDir"
                                action="sort('auditable_type')"
                                :label="__('Model')"
                            />
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Details') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($mutations as $mutation)
                            <tr wire:key="mutation-{{ $mutation->id }}" class="hover:bg-surface-subtle/50 transition-colors align-top">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums"><x-ui.datetime :value="$mutation->occurred_at" /></td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">
                                    {{ $mutation->actor_name ?? $mutation->actor_type . '#' . $mutation->actor_id }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-xs text-muted">{{ $mutation->actor_role ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @switch($mutation->event)
                                        @case('created')
                                            <x-ui.badge variant="success">{{ __('Created') }}</x-ui.badge>
                                            @break
                                        @case('updated')
                                            <x-ui.badge variant="info">{{ __('Updated') }}</x-ui.badge>
                                            @break
                                        @case('deleted')
                                            <x-ui.badge variant="danger">{{ __('Deleted') }}</x-ui.badge>
                                            @break
                                        @default
                                            <x-ui.badge>{{ $mutation->event }}</x-ui.badge>
                                    @endswitch
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">
                                    {{ class_basename($mutation->auditable_type) }}#{{ $mutation->auditable_id }}
                                </td>
                                <td class="px-table-cell-x py-table-cell-y">
                                    @php
                                        $oldValues = $mutation->old_values ?? [];
                                        $newValues = $mutation->new_values ?? [];
                                        $allKeys = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));
                                        sort($allKeys);
                                    @endphp

                                    @forelse($allKeys as $field)
                                        @php
                                            $oldVal = $oldValues[$field] ?? null;
                                            $newVal = $newValues[$field] ?? null;
                                            $isRedacted = $oldVal === '[redacted]' || $newVal === '[redacted]';
                                            $isTruncated = is_string($oldVal) && str_starts_with($oldVal, '[truncated')
                                                || is_string($newVal) && str_starts_with($newVal, '[truncated');
                                        @endphp
                                        <div class="flex items-baseline gap-2 font-mono text-xs">
                                            <span class="text-muted font-semibold min-w-[120px]">{{ $field }}:</span>
                                            @if($isRedacted || $isTruncated)
                                                <code class="text-muted italic">
                                                    {{ is_string($oldVal) ? $oldVal : json_encode($oldVal) }} → {{ is_string($newVal) ? $newVal : json_encode($newVal) }}
                                                </code>
                                            @else
                                                <code class="text-red-600 dark:text-red-400">{{ is_string($oldVal) ? $oldVal : json_encode($oldVal) }}</code>
                                                <span class="text-muted">→</span>
                                                <code class="text-green-600 dark:text-green-400">{{ is_string($newVal) ? $newVal : json_encode($newVal) }}</code>
                                            @endif
                                        </div>
                                    @empty
                                        <span class="text-muted italic text-xs">{{ __('No field changes recorded.') }}</span>
                                    @endforelse
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No mutation logs found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                {{ $mutations->links(data: ['scrollTo' => false]) }}
            </div>
        </x-ui.card>
    </div>
</div>
