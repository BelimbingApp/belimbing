<?php
/** @var \App\Base\Audit\Livewire\AuditLog\Actions $this */
/** @var \App\Base\Audit\Services\AuditLogPresenter $presenter */
?>

<div>
    <x-slot name="title">{{ __('Actions') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Actions')" :subtitle="__('Searchable HTTP, auth, console, queue, and domain action trail')" />

        <x-ui.card>
            <div class="mb-3 grid grid-cols-1 gap-2 lg:grid-cols-[minmax(18rem,1fr)_auto_auto_auto_auto]">
                <x-ui.search-input
                    id="audit-action-search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search actor, route, trace, IP, payload...') }}"
                />

                <x-ui.select id="audit-action-family" wire:model.live="filterEventFamily" aria-label="{{ __('Action family') }}">
                    <option value="">{{ __('All Sources') }}</option>
                    <option value="product">{{ __('Product Actions') }}</option>
                    <option value="http">{{ __('HTTP') }}</option>
                    <option value="auth">{{ __('Auth') }}</option>
                    <option value="console">{{ __('Console') }}</option>
                    <option value="queue">{{ __('Queue') }}</option>
                    <option value="domain">{{ __('Domain') }}</option>
                </x-ui.select>

                <x-ui.select id="audit-action-result" wire:model.live="filterResult" aria-label="{{ __('Action result') }}">
                    <option value="">{{ __('All Results') }}</option>
                    <option value="failure">{{ __('Failures') }}</option>
                    <option value="retained">{{ __('Retained') }}</option>
                </x-ui.select>

                <x-ui.select id="audit-action-diagnostics" wire:model.live="filterDiagnostics" aria-label="{{ __('Diagnostic traffic') }}">
                    <option value="hide">{{ __('Hide Diagnostics') }}</option>
                    <option value="show">{{ __('Show Diagnostics') }}</option>
                </x-ui.select>

                <x-ui.select id="filter-actor-type" wire:model.live="filterActorType" aria-label="{{ __('Actor type') }}">
                    <option value="">{{ __('All Actor Types') }}</option>
                    @foreach ($actorTypeOptions as $actorType)
                        <option value="{{ $actorType->value }}">{{ $actorType->label() }}</option>
                    @endforeach
                </x-ui.select>
            </div>

            @if ($filterDiagnostics === 'hide')
                <p class="mb-3 text-xs text-muted">
                    {{ __('Successful Livewire updates, AI event streams, and media streams are hidden. Failures still appear; switch to Show Diagnostics to inspect transport noise.') }}
                </p>
            @endif

            <x-ui.table container="flush" :caption="__('Audit action log')">
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
                            :label="__('Action')"
                        />
                        <x-ui.th>{{ __('Context') }}</x-ui.th>
                        <x-ui.th>{{ __('Result') }}</x-ui.th>
                        <x-ui.sortable-th
                            column="trace_id"
                            :sort-by="$sortBy"
                            :sort-dir="$sortDir"
                            action="sort('trace_id')"
                            :label="__('Trace')"
                        />
                        <x-ui.th align="center">{{ __('Retain') }}</x-ui.th>
                    </tr>
                </x-slot>

                @forelse($actions as $action)
                    @php
                        $summary = $presenter->actionSummary($action);
                        $actorLabel = $presenter->actorLabel($action);
                    @endphp
                    <tr wire:key="action-{{ $action->id }}" class="align-top">
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">
                            <x-ui.datetime :value="$action->occurred_at" />
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm">
                            <div class="text-ink">{{ $actorLabel }}</div>
                            <div class="mt-0.5 font-mono text-xs text-muted">{{ $action->actor_role ?? $action->actor_type }}</div>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y min-w-[14rem]">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-medium text-ink">{{ $summary['summary'] }}</span>
                                <x-ui.badge>{{ $summary['source'] }}</x-ui.badge>
                                @if ($summary['diagnostic'])
                                    <x-ui.badge variant="warning">{{ __('Diagnostic') }}</x-ui.badge>
                                @endif
                            </div>
                            <div class="mt-0.5 font-mono text-xs text-muted">{{ $action->event }}</div>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y max-w-[18rem] text-sm text-muted">
                            <div class="truncate" title="{{ $summary['context'] ?? $action->url ?? '' }}">
                                {{ $summary['context'] ?? '—' }}
                            </div>
                            @if ($action->ip_address || $action->user_agent)
                                <div class="mt-0.5 truncate text-xs" title="{{ trim(($action->ip_address ?? '').' '.($action->user_agent ?? '')) }}">
                                    {{ $action->ip_address ?? '—' }}{{ $action->user_agent ? ' · '.$action->user_agent : '' }}
                                </div>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                            <x-ui.badge :variant="$summary['variant']">{{ $summary['result'] }}</x-ui.badge>
                        </td>
                        <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-xs">
                            @if ($action->trace_id)
                                <button
                                    type="button"
                                    wire:click="openTrace('{{ $action->trace_id }}')"
                                    class="text-accent hover:underline focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                >
                                    {{ $presenter->formatTrace($action->trace_id) }}
                                </button>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-table-cell-x py-table-cell-y text-center">
                            <button
                                type="button"
                                wire:click.stop="toggleRetain({{ $action->id }})"
                                class="transition-colors {{ $action->is_retained ? 'text-accent hover:text-accent-hover' : 'text-muted/40 hover:text-muted' }}"
                                title="{{ $action->is_retained ? __('Remove retention') : __('Retain this entry') }}"
                                aria-label="{{ $action->is_retained ? __('Remove retention for this audit action') : __('Retain this audit action') }}"
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
                        <td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">
                            {{ __('No action logs match the current filters. Try Show Diagnostics if you are looking for Livewire or stream traffic.') }}
                        </td>
                    </tr>
                @endforelse
            </x-ui.table>

            <div class="mt-2">
                {{ $actions->links(data: ['scrollTo' => false]) }}
            </div>
        </x-ui.card>
    </div>

    @include('livewire.admin.audit.partials.trace-drawer')
</div>
