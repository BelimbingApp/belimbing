<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var \App\Base\Workflow\Livewire\Workflows\Show $this */
?>

<div>
    <x-slot name="title">{{ $workflow->label }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$workflow->label" :subtitle="$workflow->description ?? $workflow->code">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('admin.workflows.index') }}" wire:navigate>
                    <x-icon name="heroicon-o-arrow-left" class="w-4 h-4" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        {{-- Workflow metadata --}}
        <x-ui.card>
            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Code') }}</dt>
                    <dd class="mt-1 text-sm font-mono text-ink">{{ $workflow->code }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Module') }}</dt>
                    <dd class="mt-1 text-sm text-ink">{{ $workflow->module ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Model Class') }}</dt>
                    <dd class="mt-1 text-sm font-mono text-ink">{{ $workflow->model_class ? class_basename($workflow->model_class) : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</dt>
                    <dd class="mt-1">
                        @if($workflow->is_active)
                            <x-ui.badge variant="success">{{ __('Active') }}</x-ui.badge>
                        @else
                            <x-ui.badge variant="default">{{ __('Inactive') }}</x-ui.badge>
                        @endif
                    </dd>
                </div>
            </dl>
        </x-ui.card>

        {{-- Statuses --}}
        <x-ui.card>
            <h2 class="text-base font-medium tracking-tight text-ink mb-3">{{ __('Statuses') }}</h2>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="position"
                                :sort-by="$statusesSortBy"
                                :sort-dir="$statusesSortDir"
                                action="sortStatuses('position')"
                                :label="__('Position')"
                            />
                            <x-ui.sortable-th
                                column="code"
                                :sort-by="$statusesSortBy"
                                :sort-dir="$statusesSortDir"
                                action="sortStatuses('code')"
                                :label="__('Code')"
                            />
                            <x-ui.sortable-th
                                column="label"
                                :sort-by="$statusesSortBy"
                                :sort-dir="$statusesSortDir"
                                action="sortStatuses('label')"
                                :label="__('Label')"
                            />
                            <x-ui.sortable-th
                                column="kanban_code"
                                :sort-by="$statusesSortBy"
                                :sort-dir="$statusesSortDir"
                                action="sortStatuses('kanban_code')"
                                :label="__('Kanban')"
                            />
                            <x-ui.sortable-th
                                column="pic"
                                :sort-by="$statusesSortBy"
                                :sort-dir="$statusesSortDir"
                                action="sortStatuses('pic')"
                                :label="__('PIC')"
                            />
                            <x-ui.sortable-th
                                column="notify"
                                :sort-by="$statusesSortBy"
                                :sort-dir="$statusesSortDir"
                                action="sortStatuses('notify')"
                                :label="__('Notify')"
                            />
                            <x-ui.sortable-th
                                column="is_active"
                                :sort-by="$statusesSortBy"
                                :sort-dir="$statusesSortDir"
                                action="sortStatuses('is_active')"
                                :label="__('Active')"
                            />
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($statuses as $status)
                            <tr wire:key="status-{{ $status->id }}" class="group/row hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $status->position }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">{{ $status->code }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">{{ $status->label }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-muted">{{ $status->kanban_code ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted"
                                    x-data="{
                                        editing: false,
                                        value: @js($status->pic ? implode(', ', $status->pic) : ''),
                                    }"
                                >
                                    <template x-if="!editing">
                                        <button
                                            type="button"
                                            @click="editing = true; $nextTick(() => $refs.picInput{{ $status->id }}.focus())"
                                            class="text-left hover:text-accent transition-colors cursor-pointer min-w-[60px] inline-block"
                                            title="{{ __('Click to edit') }}"
                                        >
                                            <span x-text="value || '—'"></span>
                                            <x-icon name="heroicon-m-pencil-square" class="w-3.5 h-3.5 inline-block ml-1 opacity-0 group-hover/row:opacity-50" />
                                        </button>
                                    </template>
                                    <template x-if="editing">
                                        <input
                                            type="text"
                                            x-ref="picInput{{ $status->id }}"
                                            x-model="value"
                                            @keydown.enter="
                                                const arr = value.split(',').map(s => s.trim()).filter(s => s.length > 0);
                                                $wire.saveStatusField({{ $status->id }}, 'pic', arr.length ? arr : null);
                                                editing = false;
                                            "
                                            @keydown.escape="editing = false; value = @js($status->pic ? implode(', ', $status->pic) : '')"
                                            @blur="
                                                const arr = value.split(',').map(s => s.trim()).filter(s => s.length > 0);
                                                $wire.saveStatusField({{ $status->id }}, 'pic', arr.length ? arr : null);
                                                editing = false;
                                            "
                                            class="w-full min-w-[120px] px-input-x py-input-y text-sm border border-border-input rounded bg-surface-card text-ink focus:ring-2 focus:ring-accent focus:ring-offset-2 outline-none"
                                            placeholder="{{ __('e.g. it_support, it_manager') }}"
                                        />
                                    </template>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y text-sm text-muted"
                                    x-data="{
                                        editing: false,
                                        value: @js(($status->notifications['on_enter'] ?? []) ? implode(', ', $status->notifications['on_enter'] ?? []) : ''),
                                    }"
                                >
                                    <template x-if="!editing">
                                        <button
                                            type="button"
                                            @click="editing = true; $nextTick(() => $refs.notifyInput{{ $status->id }}.focus())"
                                            class="text-left hover:text-accent transition-colors cursor-pointer min-w-[60px] inline-block"
                                            title="{{ __('Click to edit') }}"
                                        >
                                            <span x-text="value || '—'"></span>
                                            <x-icon name="heroicon-m-pencil-square" class="w-3.5 h-3.5 inline-block ml-1 opacity-0 group-hover/row:opacity-50" />
                                        </button>
                                    </template>
                                    <template x-if="editing">
                                        <input
                                            type="text"
                                            x-ref="notifyInput{{ $status->id }}"
                                            x-model="value"
                                            @keydown.enter="
                                                const arr = value.split(',').map(s => s.trim()).filter(s => s.length > 0);
                                                $wire.saveStatusField({{ $status->id }}, 'notifications', arr.length ? { on_enter: arr, channels: ['database'] } : null);
                                                editing = false;
                                            "
                                            @keydown.escape="editing = false; value = @js(($status->notifications['on_enter'] ?? []) ? implode(', ', $status->notifications['on_enter'] ?? []) : '')"
                                            @blur="
                                                const arr = value.split(',').map(s => s.trim()).filter(s => s.length > 0);
                                                $wire.saveStatusField({{ $status->id }}, 'notifications', arr.length ? { on_enter: arr, channels: ['database'] } : null);
                                                editing = false;
                                            "
                                            class="w-full min-w-[120px] px-input-x py-input-y text-sm border border-border-input rounded bg-surface-card text-ink focus:ring-2 focus:ring-accent focus:ring-offset-2 outline-none"
                                            placeholder="{{ __('e.g. reporter, assignee') }}"
                                        />
                                    </template>
                                </td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if($status->is_active)
                                        <x-ui.badge variant="success">{{ __('Yes') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="default">{{ __('No') }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No statuses configured.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        {{-- Transitions --}}
        <x-ui.card>
            <h2 class="text-base font-medium tracking-tight text-ink mb-3">{{ __('Transitions') }}</h2>

            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <x-ui.sortable-th
                                column="from_code"
                                :sort-by="$transitionsSortBy"
                                :sort-dir="$transitionsSortDir"
                                action="sortTransitions('from_code')"
                                :label="__('From')"
                            />
                            <x-ui.sortable-th
                                column="to_code"
                                :sort-by="$transitionsSortBy"
                                :sort-dir="$transitionsSortDir"
                                action="sortTransitions('to_code')"
                                :label="__('To')"
                            />
                            <x-ui.sortable-th
                                column="label"
                                :sort-by="$transitionsSortBy"
                                :sort-dir="$transitionsSortDir"
                                action="sortTransitions('label')"
                                :label="__('Label')"
                            />
                            <x-ui.sortable-th
                                column="capability"
                                :sort-by="$transitionsSortBy"
                                :sort-dir="$transitionsSortDir"
                                action="sortTransitions('capability')"
                                :label="__('Capability')"
                            />
                            <x-ui.sortable-th
                                column="guard"
                                :sort-by="$transitionsSortBy"
                                :sort-dir="$transitionsSortDir"
                                action="sortTransitions('guard')"
                                :label="__('Guard')"
                            />
                            <x-ui.sortable-th
                                column="action"
                                :sort-by="$transitionsSortBy"
                                :sort-dir="$transitionsSortDir"
                                action="sortTransitions('action')"
                                :label="__('Action')"
                            />
                            <x-ui.sortable-th
                                column="sla_seconds"
                                :sort-by="$transitionsSortBy"
                                :sort-dir="$transitionsSortDir"
                                action="sortTransitions('sla_seconds')"
                                :label="__('SLA')"
                            />
                            <x-ui.sortable-th
                                column="is_active"
                                :sort-by="$transitionsSortBy"
                                :sort-dir="$transitionsSortDir"
                                action="sortTransitions('is_active')"
                                :label="__('Active')"
                            />
                        </tr>
                    </thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($transitions as $transition)
                            <tr wire:key="transition-{{ $transition->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">{{ $transition->from_code }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">{{ $transition->to_code }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">{{ $transition->label ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-muted">{{ $transition->capability ?? '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $transition->guard_class ? class_basename($transition->guard_class) : '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted">{{ $transition->action_class ? class_basename($transition->action_class) : '—' }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $this->formatSla($transition->sla_seconds) }}</td>
                                <td class="px-table-cell-x py-table-cell-y whitespace-nowrap">
                                    @if($transition->is_active)
                                        <x-ui.badge variant="success">{{ __('Yes') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge variant="default">{{ __('No') }}</x-ui.badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No transitions configured.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        {{-- Kanban Columns --}}
        @if($kanbanColumns->isNotEmpty())
            <x-ui.card>
                <h2 class="text-base font-medium tracking-tight text-ink mb-3">{{ __('Kanban Columns') }}</h2>

                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <x-ui.sortable-th
                                    column="position"
                                    :sort-by="$kanbanColumnsSortBy"
                                    :sort-dir="$kanbanColumnsSortDir"
                                    action="sortKanbanColumns('position')"
                                    :label="__('Position')"
                                />
                                <x-ui.sortable-th
                                    column="code"
                                    :sort-by="$kanbanColumnsSortBy"
                                    :sort-dir="$kanbanColumnsSortDir"
                                    action="sortKanbanColumns('code')"
                                    :label="__('Code')"
                                />
                                <x-ui.sortable-th
                                    column="label"
                                    :sort-by="$kanbanColumnsSortBy"
                                    :sort-dir="$kanbanColumnsSortDir"
                                    action="sortKanbanColumns('label')"
                                    :label="__('Label')"
                                />
                                <x-ui.sortable-th
                                    column="wip_limit"
                                    :sort-by="$kanbanColumnsSortBy"
                                    :sort-dir="$kanbanColumnsSortDir"
                                    action="sortKanbanColumns('wip_limit')"
                                    :label="__('WIP Limit')"
                                />
                            </tr>
                        </thead>
                        <tbody class="bg-surface-card divide-y divide-border-default">
                            @foreach($kanbanColumns as $column)
                                <tr wire:key="kanban-{{ $column->id }}" class="hover:bg-surface-subtle/50 transition-colors">
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $column->position }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap font-mono text-sm text-ink">{{ $column->code }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">{{ $column->label }}</td>
                                    <td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $column->wip_limit ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    </div>
</div>
