@use('App\Modules\People\Attendance\Support\DayTypeVocabulary')
@php($showPreviewLegend = $showPreviewLegend ?? true)
@php($compact = $compact ?? false)
@php($gridIntro = $gridIntro ?? __('Existing assignments show draft or published state; rest, off, and holiday days surface from each employee\'s work calendar.'))
@php($cellMinWidth = $compact ? 'min-w-9' : 'min-w-14')

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
        <x-ui.day-strip :days="$rosterGridDays" :leading-label="__('Employee')" :compact="$compact" />
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
                    </td>
                    @foreach ($rosterGridDays as $day)
                        @php($cell = $row['cells'][$day['date']])
                        @php($dayType = $cell['day_type'] ?? 'normal')
                        @php($dayTypeInk = DayTypeVocabulary::inkClass($dayType))
                        @php($isEmpty = $cell['state'] === 'empty')
                        @php($cellShiftId = (int) ($cell['shift_template_id'] ?? 0))
                        @php($cellPolicyId = (int) ($cell['policy_group_id'] ?? 0))
                        <td wire:key="roster-grid-cell-{{ $employee->id }}-{{ $day['date'] }}" class="relative p-0 align-top"
                            @if($canManage)
                                x-data="{ open: false, shift: {{ $cellShiftId }}, policy: {{ $cellPolicyId }} }"
                                :data-cell-shift="{{ $cellShiftId }}"
                                :data-cell-policy="{{ $cellPolicyId }}"
                            @endif
                        >
                            @if ($canManage)
                                <button
                                    type="button"
                                    @click="shift = parseInt($root.dataset.cellShift) || 0; policy = parseInt($root.dataset.cellPolicy) || 0; open = ! open"
                                    :aria-expanded="open"
                                    aria-label="{{ __('Edit override :date for :employee', ['date' => $day['date'], 'employee' => $employee->displayName()]) }}"
                                    class="block w-full {{ $cellMinWidth }} cursor-pointer text-center focus:outline-none focus:ring-2 focus:ring-accent focus:ring-inset focus:rounded-md"
                                >
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
                                </button>
                                <div x-show="open" x-cloak @click.outside="open = false" x-transition.origin.top.left class="absolute left-1/2 z-30 mt-1 w-56 -translate-x-1/2 rounded-2xl border border-border-default bg-surface-card p-3 text-left shadow-lg" role="region" aria-label="{{ __('Override :date', ['date' => $day['date']]) }}">
                                    <div class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Override') }} {{ \Carbon\CarbonImmutable::parse($day['date'])->format('M j') }}</div>
                                    <div class="mt-2 space-y-2">
                                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-muted">
                                            {{ __('Shift') }}
                                            <select x-model.number="shift" class="mt-1 w-full rounded-2xl border border-border-default bg-surface-card px-2 py-1 text-sm text-ink">
                                                <option value="0">{{ __('Choose shift') }}</option>
                                                @foreach ($shiftTemplates as $shift)
                                                    <option value="{{ $shift->id }}">{{ $shift->code }} — {{ $shift->name }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label class="block text-[11px] font-semibold uppercase tracking-wider text-muted">
                                            {{ __('Policy') }}
                                            <select x-model.number="policy" class="mt-1 w-full rounded-2xl border border-border-default bg-surface-card px-2 py-1 text-sm text-ink">
                                                <option value="0">{{ __('Choose policy') }}</option>
                                                @foreach ($policyGroups as $group)
                                                    <option value="{{ $group->id }}">{{ $group->code }} — {{ $group->name }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    </div>
                                    <div class="mt-3 flex justify-end gap-2">
                                        <button type="button" @click="open = false" class="text-xs font-medium text-muted hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 focus:rounded-sm">{{ __('Cancel') }}</button>
                                        <button type="button" @click="$wire.saveCellOverride({{ $employee->id }}, '{{ $day['date'] }}', shift, policy).then(() => { open = false })" :disabled="! shift || ! policy" class="rounded-lg bg-accent px-2.5 py-1 text-xs font-semibold text-accent-on disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1">{{ __('Save') }}</button>
                                    </div>
                                </div>
                            @else
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
