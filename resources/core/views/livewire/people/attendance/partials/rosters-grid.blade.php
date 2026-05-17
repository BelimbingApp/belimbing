@php($showPreviewLegend = $showPreviewLegend ?? true)
@php($gridIntro = $gridIntro ?? __('Existing assignments show draft or published state; rest, off, and holiday days surface from each employee\'s work calendar.'))

<div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
    <div>
        <h3 class="text-base font-semibold text-ink">{{ __('Roster grid') }}</h3>
        <p class="mt-1 text-sm text-muted">{{ $gridIntro }}</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <x-ui.badge variant="success">{{ __('Published') }}</x-ui.badge>
        <x-ui.badge variant="warning">{{ __('Draft') }}</x-ui.badge>
        @if ($showPreviewLegend)
            <x-ui.badge variant="info">{{ __('Preview') }}</x-ui.badge>
        @endif
        <span class="text-[11px] text-muted">·</span>
        <span class="inline-flex items-center gap-1 rounded-full bg-day-rest px-2 py-0.5 text-[11px] font-medium text-day-rest-ink">{{ __('Rest') }}</span>
        <span class="inline-flex items-center gap-1 rounded-full bg-day-off px-2 py-0.5 text-[11px] font-medium text-day-off-ink">{{ __('Off') }}</span>
        <span class="inline-flex items-center gap-1 rounded-full bg-day-holiday px-2 py-0.5 text-[11px] font-medium text-day-holiday-ink">{{ __('Holiday') }}</span>
    </div>
</div>

<div class="mt-4 overflow-x-auto rounded-2xl border border-border-default">
    <table class="min-w-full divide-y divide-border-default text-xs">
        <thead class="bg-surface-subtle/80">
            <tr>
                <th class="sticky left-0 z-10 w-40 min-w-40 bg-surface-subtle/95 px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employee') }}</th>
                @foreach ($rosterGridDays as $day)
                    <th class="min-w-20 px-1.5 py-table-header-y text-center text-[11px] font-semibold uppercase tracking-wider text-muted" wire:key="roster-grid-day-{{ $day['date'] }}">
                        <div>{{ $day['day'] }}</div>
                        <div class="font-normal normal-case tracking-normal text-muted">{{ $day['label'] }}</div>
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-border-default bg-surface-card">
            @forelse ($rosterGridRows as $row)
                @php($employee = $row['employee'])
                @if ($loop->first || $row['group'] !== $rosterGridRows[$loop->index - 1]['group'])
                    <tr wire:key="roster-grid-group-{{ $loop->index }}">
                        <td colspan="{{ count($rosterGridDays) + 1 }}" class="sticky left-0 z-10 bg-surface-subtle px-table-cell-x py-1 text-[11px] font-semibold uppercase tracking-wide text-muted">
                            {{ $row['group'] }}
                        </td>
                    </tr>
                @endif
                <tr wire:key="roster-grid-row-{{ $employee->id }}" class="hover:bg-surface-subtle/50">
                    <td class="sticky left-0 z-10 w-40 min-w-40 bg-surface-card px-table-cell-x py-1.5 align-top">
                        <div class="truncate text-sm font-medium text-ink" title="{{ $employee->full_name }}">{{ $employee->displayName() }}</div>
                        <div class="text-[11px] text-muted tabular-nums">{{ $employee->employee_number }}</div>
                    </td>
                    @foreach ($rosterGridDays as $day)
                        @php($cell = $row['cells'][$day['date']])
                        @php($dayTypeClass = match ($cell['day_type'] ?? 'normal') {
                            'rest' => 'bg-day-rest',
                            'off' => 'bg-day-off',
                            'holiday' => 'bg-day-holiday',
                            default => '',
                        })
                        @php($stateBorderClass = match ($cell['state']) {
                            'published' => 'border-l-2 border-status-success',
                            'draft' => 'border-l-2 border-status-warning',
                            'preview' => 'border-l-2 border-status-info border-dashed',
                            default => '',
                        })
                        @php($dayTypeInkClass = match ($cell['day_type'] ?? 'normal') {
                            'holiday' => 'text-day-holiday-ink',
                            'rest' => 'text-day-rest-ink',
                            'off' => 'text-day-off-ink',
                            default => 'text-muted',
                        })
                        <td class="{{ $dayTypeClass }} group px-1 py-1 text-center align-top" wire:key="roster-grid-cell-{{ $employee->id }}-{{ $day['date'] }}" title="{{ $cell['title'] }}">
                            @if ($cell['state'] === 'empty')
                                @if (($cell['day_type'] ?? 'normal') === 'normal')
                                    <span class="text-muted">·</span>
                                @else
                                    <span class="text-[10px] font-medium uppercase tracking-wide {{ $dayTypeInkClass }}">{{ $cell['label'] }}</span>
                                @endif
                            @else
                                <div class="inline-flex min-w-14 flex-col items-center rounded-md bg-surface-card {{ $stateBorderClass }} px-1.5 py-0.5">
                                    <span class="text-[12px] font-semibold leading-tight text-ink">{{ $cell['label'] }}</span>
                                    @if ($cell['on_non_working_day'] ?? false)
                                        <span class="text-[9px] font-medium uppercase leading-tight tracking-wide {{ $dayTypeInkClass }}">{{ $cell['day_type_label'] }}</span>
                                    @endif
                                </div>
                            @endif
                            @if ($canManage)
                                <button type="button" wire:click="saveCellOverride({{ $employee->id }}, '{{ $day['date'] }}')" aria-label="{{ __('Override :date for :employee', ['date' => $day['date'], 'employee' => $employee->displayName()]) }}" class="mt-0.5 block w-full text-[10px] font-medium text-muted opacity-0 transition-opacity hover:text-accent group-hover:opacity-100 focus:opacity-100 motion-reduce:opacity-100">
                                    {{ __('Edit') }}
                                </button>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($rosterGridDays) + 1 }}" class="px-table-cell-x py-table-cell-y text-sm text-muted">
                        {{ __('No employees available for the roster grid.') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
