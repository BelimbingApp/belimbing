@php($rosterIncomplete = $companyEmployeeCount === 0 || $shiftTemplates->isEmpty() || $policyGroups->isEmpty())
@php($listPeriodFirst = $rosterGridDays[0]['date'] ?? null)
@php($listPeriodLast = $rosterGridDays[array_key_last($rosterGridDays)]['date'] ?? null)
@php($listPeriodLabel = $listPeriodFirst && $listPeriodLast
    ? ($listScope === 'month'
        ? \Carbon\CarbonImmutable::parse($listPeriodFirst)->format('F Y')
        : \Carbon\CarbonImmutable::parse($listPeriodFirst)->format('j M').' – '.\Carbon\CarbonImmutable::parse($listPeriodLast)->format('j M'))
    : '')
@php($today = \Carbon\CarbonImmutable::today())
@php($listPeriodIsCurrent = $listScope === 'month'
    ? ($listPeriodFirst === $today->startOfMonth()->toDateString())
    : ($listPeriodFirst === $today->startOfWeek(\Carbon\CarbonImmutable::MONDAY)->toDateString()))
@php($currentLabel = $listScope === 'month' ? __('This month') : __('This week'))
@php($prevLabel = $listScope === 'month' ? __('Previous month') : __('Previous week'))
@php($nextLabel = $listScope === 'month' ? __('Next month') : __('Next week'))
<div class="space-y-4">
    @if ($rosterIncomplete && ! $isMySchedule)
        <x-ui.alert variant="warning">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ __('Roster setup needs employees, shifts, and policy groups before you can publish.') }}</span>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button as="a" size="sm" variant="secondary" href="{{ route('people.attendance.shifts') }}">{{ __('Set up shifts') }}</x-ui.button>
                    <x-ui.button as="a" size="sm" variant="secondary" href="{{ route('people.attendance.policy-groups') }}">{{ __('Set up policies') }}</x-ui.button>
                </div>
            </div>
        </x-ui.alert>
    @endif

    <x-ui.tabs :tabs="$isMySchedule
        ? [['id' => 'calendar', 'label' => __('Calendar'), 'icon' => 'heroicon-o-calendar-days']]
        : [
            ['id' => 'calendar', 'label' => __('Calendar'), 'icon' => 'heroicon-o-calendar-days'],
            ['id' => 'records', 'label' => __('Records'), 'icon' => 'heroicon-o-table-cells'],
          ]" default="calendar">
        <x-ui.tab id="calendar">
            <x-ui.card>
                @if (! empty($rosterListSummary))
                    <p class="mb-3 text-sm {{ $rosterListSummary['hasIssues'] ? 'text-ink' : 'text-muted' }}">
                        @if ($rosterListSummary['hasIssues'])
                            <span class="font-medium">{{ $rosterListSummary['sentence'] }}</span>
                        @else
                            {{ $rosterListSummary['sentence'] }}
                        @endif
                    </p>
                @endif

                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-baseline gap-3">
                        <h3 class="text-base font-semibold text-ink">{{ $isMySchedule ? __('Your shifts') : __('Roster') }}</h3>
                        @if ($listPeriodLabel !== '')
                            <span class="text-sm text-muted">{{ $listPeriodLabel }}</span>
                        @endif
                        @if ($canManage && ! empty($acknowledgmentCount))
                            <span class="text-xs text-muted" title="{{ __('Acknowledged / Total') }}">
                                {{ __(':ack / :total ack.', ['ack' => $acknowledgmentCount['acknowledged'], 'total' => $acknowledgmentCount['total']]) }}
                            </span>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($canManage)
                            <x-ui.button type="button" size="sm" variant="ghost" wire:click="exportRosterCsv" title="{{ __('Export visible roster as CSV') }}">
                                <x-icon name="heroicon-o-arrow-down-tray" class="h-4 w-4" />
                                <span class="sr-only">{{ __('Export CSV') }}</span>
                            </x-ui.button>
                        @endif
                        <fieldset class="inline-flex rounded-xl border border-border-default p-0.5">
                            <legend class="sr-only">{{ __('Calendar scope') }}</legend>
                            <button type="button" wire:click="setListScope('week')" class="rounded-lg px-3 py-1 text-xs font-medium @if($listScope === 'week') bg-surface-subtle text-ink @else text-muted hover:text-ink @endif focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1">{{ __('Week') }}</button>
                            <button type="button" wire:click="setListScope('month')" class="rounded-lg px-3 py-1 text-xs font-medium @if($listScope === 'month') bg-surface-subtle text-ink @else text-muted hover:text-ink @endif focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1">{{ __('Month') }}</button>
                        </fieldset>
                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="goToPreviousWeek" aria-label="{{ $prevLabel }}">
                            <x-icon name="heroicon-o-chevron-left" class="h-4 w-4" />
                            <span>{{ __('Prev') }}</span>
                        </x-ui.button>
                        <x-ui.button type="button" size="sm" :variant="$listPeriodIsCurrent ? 'secondary' : 'ghost'" wire:click="goToThisWeek">
                            {{ $currentLabel }}
                        </x-ui.button>
                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="goToNextWeek" aria-label="{{ $nextLabel }}">
                            <span>{{ __('Next') }}</span>
                            <x-icon name="heroicon-o-chevron-right" class="h-4 w-4" />
                        </x-ui.button>
                    </div>
                </div>

                @if (! $isMySchedule)
                    <div class="mt-4">
                        @include('livewire.people.attendance.partials.rosters-filter-prose')
                    </div>
                @endif

                <div class="mt-4">
                    @include('livewire.people.attendance.partials.rosters-grid', [
                        'showPreviewLegend' => false,
                        'compact' => $listScope === 'month',
                        'gridIntro' => $listPeriodLabel ? __('Range: :period', ['period' => $listPeriodLabel]) : null,
                    ])
                </div>

                @if ($isMySchedule && $listScope === 'week')
                    <div class="mt-4 flex items-center gap-3">
                        @if ($acknowledgedForPeriod)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-status-success/10 px-3 py-1.5 text-sm font-medium text-status-success">
                                <x-icon name="heroicon-o-check-circle" class="h-4 w-4" />
                                {{ __('Acknowledged') }}
                            </span>
                        @else
                            <x-ui.button type="button" variant="primary" size="sm" wire:click="acknowledgeSchedule('{{ $gridPeriodStart }}', '{{ $gridPeriodEnd }}')">
                                <x-icon name="heroicon-o-check" class="h-4 w-4" />
                                {{ __('Acknowledge this week') }}
                            </x-ui.button>
                            <span class="text-xs text-muted">{{ __('Tap to confirm you\'ve seen your shifts for this week.') }}</span>
                        @endif
                    </div>
                @endif
            </x-ui.card>
        </x-ui.tab>

        @if (! $isMySchedule)
        <x-ui.tab id="records">
            <x-ui.card>
                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('No.') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employee') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Period') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Shift') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Policy') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Pattern') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Rev') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-default bg-surface-card">
                            @forelse ($rosterAssignments as $assignment)
                                <tr wire:key="roster-assignment-row-{{ $assignment->id }}">
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted tabular-nums">{{ $loop->iteration }}</td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="font-medium text-ink">{{ $assignment->employee?->full_name ?? __('Cohort default') }}</div>
                                        <div class="text-xs text-muted tabular-nums">{{ $assignment->employee?->employee_number ?? '—' }}</div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted tabular-nums">
                                        <div>{{ $assignment->effective_from?->format('Y-m-d') ?? '—' }}</div>
                                        <div>{{ $assignment->effective_to?->format('Y-m-d') ?? __('Open ended') }}</div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="font-medium text-ink">{{ $assignment->shiftTemplate?->code ?? '—' }}</div>
                                        <div class="text-xs text-muted">{{ $assignment->shiftTemplate?->name ?? '' }}</div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="text-ink">{{ $assignment->policyGroup?->code ?? '—' }}</div>
                                        <div class="text-xs text-muted">{{ $assignment->policyGroup?->name ?? '' }}</div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted">{{ $assignment->rosterPattern?->code ?? '—' }}</td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <x-ui.badge :variant="$assignment->publish_state === 'published' ? 'success' : 'warning'">{{ $this->statusLabel($assignment->publish_state) }}</x-ui.badge>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted tabular-nums">{{ $assignment->revision }}</td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="flex justify-end gap-2">
                                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="editRosterAssignment({{ $assignment->id }})">{{ __('Edit') }}</x-ui.button>
                                            <x-ui.button type="button" size="sm" variant="danger" wire:click="deleteRosterAssignment({{ $assignment->id }})" wire:confirm="{{ __('Delete this roster assignment?') }}">{{ __('Delete') }}</x-ui.button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-table-cell-x py-10 text-center text-sm text-muted">
                                        @if ($rosterIncomplete)
                                            {{ __('No roster assignments yet. Finish the setup steps above, then click "New roster assignment".') }}
                                        @else
                                            {{ __('No roster assignments yet. Click "New roster assignment" to draft the first one.') }}
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        </x-ui.tab>
        @endif
    </x-ui.tabs>

    @if (! $isMySchedule)
    <div class="grid gap-4 xl:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]">
        <x-ui.card>
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-base font-semibold text-ink">{{ __('Roster patterns') }}</h3>
                <span class="text-xs text-muted">{{ __('Build once, reuse safely') }}</span>
            </div>

            <div class="mt-4 space-y-3">
                @forelse ($rosterPatterns as $pattern)
                    <div class="rounded-2xl border border-border-default p-3" wire:key="roster-pattern-{{ $pattern->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-medium text-ink">{{ $pattern->code }} — {{ $pattern->name }}</div>
                                <div class="mt-1 text-xs text-muted">{{ __('Type: :type', ['type' => $this->statusLabel($pattern->pattern_type)]) }}</div>
                            </div>
                            <x-ui.badge :variant="$pattern->status === 'published' ? 'success' : 'warning'">{{ $this->statusLabel($pattern->status) }}</x-ui.badge>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach (($pattern->pattern_definition['days'] ?? []) as $day)
                                <span class="rounded-full bg-surface-subtle px-2.5 py-1 text-xs text-muted" wire:key="roster-pattern-day-{{ $pattern->id }}-{{ $loop->index }}">
                                    {{ __('Day :day: :shift', ['day' => ((int) ($day['offset'] ?? 0)) + 1, 'shift' => $day['shift_code'] ?? '—']) }}
                                </span>
                            @endforeach
                            @foreach (($pattern->pattern_definition['weekdays'] ?? []) as $weekday => $day)
                                <span class="rounded-full bg-surface-subtle px-2.5 py-1 text-xs text-muted" wire:key="roster-pattern-weekday-{{ $pattern->id }}-{{ $weekday }}">
                                    {{ __(':day: :shift', ['day' => __(ucfirst((string) $weekday)), 'shift' => $day['shift_code'] ?? '—']) }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-muted">{{ __('No roster patterns configured yet. Start with the most common weekly or rotating cycle before assigning people.') }}</p>
                @endforelse
            </div>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-base font-semibold text-ink">{{ __('Spreadsheet intake') }}</h3>
            <p class="mt-1 text-sm text-muted">{{ __('Paste rows as employee_number, date, shift_code, policy_group_code, notes. Rows become draft one-day roster rows or dated overrides on existing assignments.') }}</p>
            <div class="mt-4 space-y-3">
                <x-ui.textarea id="attendance-roster-list-spreadsheet" wire:model.live.debounce.300ms="spreadsheetRosterRows" rows="6" label="{{ __('Rows') }}" :error="$errors->first('spreadsheetRosterRows')" />
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="rounded-2xl border border-border-default p-3 flex-1 min-w-0">
                        <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Preview') }}</div>
                        <div class="mt-2 space-y-1 text-sm text-muted">
                            @forelse ($spreadsheetPreviewRows as $row)
                                <div>{{ $row['employee_number'] }} · {{ $row['date'] }} · {{ $row['shift_code'] }} · {{ $row['policy_code'] }}</div>
                            @empty
                                <div>{{ __('No parsed rows yet.') }}</div>
                            @endforelse
                        </div>
                    </div>
                    <x-ui.button type="button" variant="primary" wire:click="importSpreadsheetRosterRows">{{ __('Import draft rows') }}</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    </div>
    @endif
</div>
