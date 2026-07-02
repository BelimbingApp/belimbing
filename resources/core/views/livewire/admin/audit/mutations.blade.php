<?php
/** @var \App\Base\Audit\Livewire\AuditLog\Mutations $this */
/** @var \App\Base\Audit\Services\AuditLogPresenter $presenter */
?>

<div>
    <x-slot name="title">{{ __('Data Mutations') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Data Mutations')" :subtitle="__('Audit trail for all data changes')" />

        <x-ui.card>
            <div class="mb-2 flex items-center gap-3">
                <div class="flex-1">
                    <x-ui.search-input
                        id="audit-mutation-search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search actor, model, subject, event, or trace...') }}"
                    />
                </div>
                <x-ui.select id="filter-event" wire:model.live="filterEvent" aria-label="{{ __('Mutation event') }}">
                    <option value="">{{ __('All Events') }}</option>
                    <option value="created">{{ __('Created') }}</option>
                    <option value="updated">{{ __('Updated') }}</option>
                    <option value="deleted">{{ __('Deleted') }}</option>
                </x-ui.select>
            </div>

            <x-ui.table container="flush" :caption="__('Data mutation audit log')">
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
                            :label="__('Event')"
                        />
                        <x-ui.sortable-th
                            column="auditable_type"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('auditable_type')"
                            :label="__('Subject')"
                        />
                        <x-ui.th>{{ __('Details') }}</x-ui.th>
                        <x-ui.sortable-th
                            column="trace_id"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('trace_id')"
                            :label="__('Trace')"
                        />
                    </tr>
                </x-slot>

                @forelse($mutations as $mutation)
                    @php
                        $diffs = $presenter->mutationDiffs($mutation);
                        $actorLabel = $presenter->actorLabel($mutation);
                    @endphp
                    <tr wire:key="mutation-{{ $mutation->id }}" class="align-top">
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">
                            <x-ui.datetime :value="$mutation->occurred_at" />
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm">
                            <div class="text-ink">{{ $actorLabel }}</div>
                            <div class="mt-0.5 font-mono text-xs text-muted">{{ $mutation->actor_role ?? $mutation->actor_type }}</div>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                            <x-ui.badge :variant="$presenter->mutationEventVariant($mutation->event)">
                                {{ $presenter->mutationEventLabel($mutation->event) }}
                            </x-ui.badge>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                            <div class="font-mono text-sm text-ink">{{ $presenter->mutationLabel($mutation) }}</div>
                            <div class="mt-0.5 font-mono text-xs text-muted">{{ class_basename($mutation->auditable_type) }}#{{ $mutation->auditable_id }}</div>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y">
                            @forelse($diffs as $diff)
                                <div class="flex items-baseline gap-2 font-mono text-xs">
                                    <span class="min-w-[120px] font-semibold text-muted">{{ $diff['field'] }}:</span>
                                    @if($diff['sensitive'])
                                        <code class="text-muted italic">{{ $diff['old'] }} → {{ $diff['new'] }}</code>
                                    @else
                                        <code class="text-status-danger">{{ $diff['old'] }}</code>
                                        <span class="text-muted">→</span>
                                        <code class="text-status-success">{{ $diff['new'] }}</code>
                                    @endif
                                </div>
                            @empty
                                <span class="text-xs italic text-muted">{{ __('No field changes recorded.') }}</span>
                            @endforelse
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-xs">
                            @if ($mutation->trace_id)
                                <button
                                    type="button"
                                    wire:click="openTrace('{{ $mutation->trace_id }}')"
                                    class="text-accent hover:underline focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                >
                                    {{ $presenter->formatTrace($mutation->trace_id) }}
                                </button>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No mutation logs found.') }}</td>
                    </tr>
                @endforelse
            </x-ui.table>

            <div class="mt-2">
                <x-ui.pagination :paginator="$mutations" :perPageOptions="$this->perPageOptions()" :perPage="$perPage" />
            </div>
        </x-ui.card>
    </div>

    @include('livewire.admin.audit.partials.trace-drawer')
</div>
