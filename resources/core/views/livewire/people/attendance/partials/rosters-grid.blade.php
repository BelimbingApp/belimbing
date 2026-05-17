@use('App\Modules\People\Attendance\Support\DayTypeVocabulary')
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
        <x-ui.day-strip :days="$rosterGridDays" :leading-label="__('Employee')" />
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
                        @php($dayType = $cell['day_type'] ?? 'normal')
                        @php($dayTypeInk = DayTypeVocabulary::inkClass($dayType))
                        @php($isEmpty = $cell['state'] === 'empty')
                        <td wire:key="roster-grid-cell-{{ $employee->id }}-{{ $day['date'] }}" class="p-0 align-top">
                            <x-ui.day-tile
                                :day-type="$dayType"
                                :state="$isEmpty ? null : $cell['state']"
                                :tooltip="$cell['title']"
                                :empty="$isEmpty"
                                :empty-label="$cell['label']"
                            >
                                <span class="text-[12px] font-semibold leading-tight text-ink">{{ $cell['label'] }}</span>
                                @if ($cell['on_non_working_day'] ?? false)
                                    <span class="text-[9px] font-medium uppercase leading-tight tracking-wide {{ $dayTypeInk }}">{{ $cell['day_type_label'] }}</span>
                                @endif
                            </x-ui.day-tile>
                            @if ($canManage)
                                <button type="button" wire:click="saveCellOverride({{ $employee->id }}, '{{ $day['date'] }}')" aria-label="{{ __('Edit override :date for :employee', ['date' => $day['date'], 'employee' => $employee->displayName()]) }}" class="mt-0.5 block w-full text-[10px] font-medium text-muted opacity-0 transition-opacity hover:text-accent group-hover:opacity-100 focus:opacity-100 motion-reduce:opacity-100">
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
