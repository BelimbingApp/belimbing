@php($rosterIncomplete = $companyEmployeeCount === 0 || $shiftTemplates->isEmpty() || $policyGroups->isEmpty())
@php($listPeriodFirst = $rosterGridDays[0]['date'] ?? null)
@php($listPeriodLast = $rosterGridDays[array_key_last($rosterGridDays)]['date'] ?? null)
@php($listPeriodLabel = $listPeriodFirst && $listPeriodLast
    ? \Carbon\CarbonImmutable::parse($listPeriodFirst)->format('M j').' — '.\Carbon\CarbonImmutable::parse($listPeriodLast)->format('M j')
    : '')
@php($listPeriodIsThisWeek = $listPeriodFirst === \Carbon\CarbonImmutable::today()->startOfWeek(\Carbon\CarbonImmutable::MONDAY)->toDateString())
@php($filterContext = $this->rosterListFilterContext($departments, $workforceClasses))

<div class="space-y-4">
    @if ($rosterIncomplete)
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

    <x-ui.tabs :tabs="[
        ['id' => 'calendar', 'label' => __('Calendar'), 'icon' => 'heroicon-o-calendar-days'],
        ['id' => 'records', 'label' => __('Records'), 'icon' => 'heroicon-o-table-cells'],
    ]" default="calendar">
        <x-ui.tab id="calendar">
            <x-ui.card>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-baseline gap-3">
                        <h3 class="text-base font-semibold text-ink">{{ __('Roster') }}</h3>
                        @if ($listPeriodLabel !== '')
                            <span class="text-sm text-muted">{{ $listPeriodLabel }}</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="goToPreviousWeek" aria-label="{{ __('Previous week') }}">
                            <x-icon name="heroicon-o-chevron-left" class="h-4 w-4" />
                            <span>{{ __('Prev') }}</span>
                        </x-ui.button>
                        <x-ui.button type="button" size="sm" :variant="$listPeriodIsThisWeek ? 'secondary' : 'ghost'" wire:click="goToThisWeek">
                            {{ __('This week') }}
                        </x-ui.button>
                        <x-ui.button type="button" size="sm" variant="ghost" wire:click="goToNextWeek" aria-label="{{ __('Next week') }}">
                            <span>{{ __('Next') }}</span>
                            <x-icon name="heroicon-o-chevron-right" class="h-4 w-4" />
                        </x-ui.button>
                    </div>
                </div>

                <p class="mt-4 flex flex-wrap items-baseline gap-x-1 gap-y-2 text-sm text-ink-soft" x-data="{ open: null }">
                    {{ __('Showing') }}
                    <span class="font-semibold text-ink tabular-nums">{{ method_exists($employees, 'total') ? $employees->total() : $employees->count() }}</span>
                    {{ __('of') }}
                    <span class="font-semibold text-ink tabular-nums">{{ $companyEmployeeCount }}</span>
                    {{ __('employees in') }}

                    {{-- Department --}}
                    <span class="relative inline-block" @click.outside="open === 'department' && (open = null)">
                        <button type="button" id="roster-filter-prose-department-toggle" @click="open = (open === 'department' ? null : 'department')" :aria-expanded="open === 'department'" aria-controls="roster-filter-prose-department-panel" class="font-medium text-ink underline decoration-dashed decoration-border-default underline-offset-4 hover:decoration-accent focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 focus:rounded-sm">{{ $filterContext['departmentLabel'] }}</button>
                        <div id="roster-filter-prose-department-panel" x-show="open === 'department'" x-cloak x-transition.origin.top.left class="absolute left-0 z-20 mt-2 w-64 rounded-2xl border border-border-default bg-surface-card p-3 shadow-lg" role="region" aria-labelledby="roster-filter-prose-department-toggle">
                            <x-ui.select id="roster-filter-prose-department" wire:model.live="rosterDepartmentId" label="{{ __('Department') }}">
                                <option value="">{{ __('All departments') }}</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}">{{ $department->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                    </span>

                    <span>,</span>

                    {{-- Workforce class --}}
                    <span class="relative inline-block" @click.outside="open === 'workforce' && (open = null)">
                        <button type="button" id="roster-filter-prose-workforce-toggle" @click="open = (open === 'workforce' ? null : 'workforce')" :aria-expanded="open === 'workforce'" aria-controls="roster-filter-prose-workforce-panel" class="font-medium text-ink underline decoration-dashed decoration-border-default underline-offset-4 hover:decoration-accent focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 focus:rounded-sm">{{ $filterContext['workforceClassLabel'] }}</button>
                        <div id="roster-filter-prose-workforce-panel" x-show="open === 'workforce'" x-cloak x-transition.origin.top.left class="absolute left-0 z-20 mt-2 w-64 rounded-2xl border border-border-default bg-surface-card p-3 shadow-lg" role="region" aria-labelledby="roster-filter-prose-workforce-toggle">
                            <x-ui.select id="roster-filter-prose-workforce" wire:model.live="rosterWorkforceClassId" label="{{ __('Workforce class') }}">
                                <option value="">{{ __('All workforce classes') }}</option>
                                @foreach ($workforceClasses as $entry)
                                    <option value="{{ $entry->id }}">{{ $entry->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </div>
                    </span>

                    <span>,</span>

                    {{-- Status --}}
                    <span class="relative inline-block" @click.outside="open === 'status' && (open = null)">
                        <button type="button" id="roster-filter-prose-status-toggle" @click="open = (open === 'status' ? null : 'status')" :aria-expanded="open === 'status'" aria-controls="roster-filter-prose-status-panel" class="font-medium text-ink underline decoration-dashed decoration-border-default underline-offset-4 hover:decoration-accent focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 focus:rounded-sm">{{ $filterContext['statusLabel'] }}</button>
                        <div id="roster-filter-prose-status-panel" x-show="open === 'status'" x-cloak x-transition.origin.top.left class="absolute left-0 z-20 mt-2 w-56 rounded-2xl border border-border-default bg-surface-card p-3 shadow-lg" role="region" aria-labelledby="roster-filter-prose-status-toggle">
                            <x-ui.select id="roster-filter-prose-status" wire:model.live="rosterEmployeeStatus" label="{{ __('Status') }}">
                                <option value="">{{ __('Any status') }}</option>
                                <option value="active">{{ __('Active') }}</option>
                                <option value="probation">{{ __('Probation') }}</option>
                                <option value="pending">{{ __('Pending') }}</option>
                                <option value="inactive">{{ __('Inactive') }}</option>
                                <option value="terminated">{{ __('Terminated') }}</option>
                            </x-ui.select>
                        </div>
                    </span>

                    <span>.</span>

                    @if ($filterContext['hasActiveFilters'])
                        <button type="button" wire:click="clearRosterFilters" class="ml-2 text-xs font-medium text-accent hover:underline focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-1 focus:rounded-sm">{{ __('Clear filters') }}</button>
                    @endif
                </p>

                <div class="mt-4">
                    @include('livewire.people.attendance.partials.rosters-grid', [
                        'showPreviewLegend' => false,
                        'gridIntro' => __('Who is working :period. Click "Edit" on any cell to change one date without leaving the roster.', ['period' => $listPeriodLabel ?: __('this period')]),
                    ])
                </div>
            </x-ui.card>
        </x-ui.tab>

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
    </x-ui.tabs>

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
</div>
