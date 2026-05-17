<div class="space-y-4">
    @php($rosterIncomplete = $companyEmployeeCount === 0 || $shiftTemplates->isEmpty() || $policyGroups->isEmpty())

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

    @if ($editingRosterAssignmentId !== '' && $editingEmployee !== null)
        <x-ui.alert variant="info">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span>{{ __('Editing roster assignment for :name (:number). Changes update this assignment in place and bump its revision.', ['name' => $editingEmployee->displayName(), 'number' => $editingEmployee->employee_number]) }}</span>
                <x-ui.button type="button" size="sm" variant="ghost" wire:click="cancelRosterForm">{{ __('Cancel edit') }}</x-ui.button>
            </div>
        </x-ui.alert>
    @endif

    <x-ui.card>
        <form wire:submit="saveRosterAssignment" class="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(360px,0.55fr)]">
            <div class="space-y-4">
                @if ($editingRosterAssignmentId === '')
                <div class="space-y-3">
                    @include('livewire.people.attendance.partials.rosters-filter-prose')

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="text-sm text-muted">
                            {{ __(':selected selected.', ['selected' => $selectedEmployeeCount]) }}
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="selectVisibleRosterEmployees">{{ __('Select visible') }}</x-ui.button>
                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="selectAllFilteredRosterEmployees">{{ __('Select all filtered') }}</x-ui.button>
                            <x-ui.button type="button" size="sm" variant="ghost" wire:click="clearRosterSelection">{{ __('Clear selection') }}</x-ui.button>
                        </div>
                    </div>

                    @error('selectedRosterEmployeeIds')
                        <p class="text-sm text-status-danger">{{ $message }}</p>
                    @enderror
                </div>

                <div class="overflow-x-auto rounded-2xl border border-border-default">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="w-12 px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Use') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employee') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Group') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Work profile') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-default bg-surface-card">
                            @forelse ($employees as $employee)
                                <tr wire:key="roster-employee-{{ $employee->id }}" class="hover:bg-surface-subtle/50">
                                    <td class="px-table-cell-x py-table-cell-y align-top">
                                        <input
                                            type="checkbox"
                                            wire:model.live="selectedRosterEmployeeIds"
                                            value="{{ $employee->id }}"
                                            @disabled($rosterSelectAllFiltered)
                                            class="h-4 w-4 rounded border-border-input bg-surface-card accent-accent"
                                        />
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y align-top">
                                        <div class="font-medium text-ink">{{ $employee->full_name }}</div>
                                        <div class="text-xs text-muted tabular-nums">{{ $employee->employee_number }}</div>
                                        <div class="text-xs text-muted">{{ $employee->designation ?? '-' }}</div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y align-top">
                                        <div class="text-sm text-default">{{ $employee->department?->name ?? '-' }}</div>
                                        <div class="text-xs text-muted">{{ $employee->department?->type?->name ?? '-' }}</div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y align-top">
                                        <div class="text-sm text-default">{{ $employee->workProfile?->organizationUnit?->name ?? '-' }}</div>
                                        <div class="text-xs text-muted">{{ __('Cost') }}: {{ $employee->workProfile?->costCenter?->name ?? '-' }}</div>
                                        <div class="text-xs text-muted">{{ __('Class') }}: {{ $employee->workProfile?->workforceClass?->name ?? '-' }}</div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-table-cell-x py-table-cell-y text-sm text-muted">{{ __('No employees match the current roster filters.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if (method_exists($employees, 'links'))
                    <div>{{ $employees->links() }}</div>
                @endif
                @endif {{-- /editingRosterAssignmentId === '' --}}
            </div>

            <div class="space-y-4">
                <div class="rounded-2xl border border-border-default p-card-inner">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-ink">{{ __('Assignment') }}</h3>
                            <p class="mt-1 text-sm text-muted">{{ __('Apply the same roster assignment to the selected employees.') }}</p>
                        </div>
                        <x-ui.badge :variant="$selectedEmployeeCount > 0 ? 'success' : 'warning'">
                            {{ __(':count selected', ['count' => $selectedEmployeeCount]) }}
                        </x-ui.badge>
                    </div>

                    @if ($rosterSelectAllFiltered)
                        <x-ui.alert variant="info" class="mt-4">
                            {{ __('All currently filtered employees are selected. Narrow the filters before saving if this population is too broad.') }}
                        </x-ui.alert>
                    @endif

                    <div class="mt-4 space-y-4">
                        <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                            <x-ui.select id="attendance-roster-template" wire:model="rosterTemplateKey" label="{{ __('Saved template') }}" :error="$errors->first('rosterTemplateKey')">
                                <option value="">{{ __('Choose template') }}</option>
                                @foreach ($rosterTemplates as $template)
                                    <option value="{{ $template['key'] }}">{{ $template['name'] }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.button type="button" variant="secondary" wire:click="applyRosterTemplate">{{ __('Apply') }}</x-ui.button>
                        </div>

                        <x-ui.select id="attendance-roster-pattern" wire:model="rosterPatternId" label="{{ __('Repeat pattern') }}" :error="$errors->first('rosterPatternId')">
                            <option value="">{{ __('No pattern - use fixed shift') }}</option>
                            @foreach ($rosterPatterns as $pattern)
                                <option value="{{ $pattern->id }}">{{ $pattern->code }} - {{ $pattern->name }}</option>
                            @endforeach
                        </x-ui.select>

                        <x-ui.select id="attendance-roster-shift" wire:model="rosterShiftTemplateId" label="{{ __('Shift') }}" required :error="$errors->first('rosterShiftTemplateId')">
                            <option value="">{{ __('Choose shift') }}</option>
                            @foreach ($shiftTemplates as $shift)
                                <option value="{{ $shift->id }}">{{ $shift->code }} - {{ $shift->name }}</option>
                            @endforeach
                        </x-ui.select>

                        <x-ui.select id="attendance-roster-policy" wire:model="rosterPolicyGroupId" label="{{ __('Policy group') }}" required :error="$errors->first('rosterPolicyGroupId')">
                            <option value="">{{ __('Choose policy') }}</option>
                            @foreach ($policyGroups as $group)
                                <option value="{{ $group->id }}">{{ $group->code }} - {{ $group->name }}</option>
                            @endforeach
                        </x-ui.select>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-ui.input id="attendance-roster-effective-from" type="date" wire:model="rosterEffectiveFrom" label="{{ __('Starts on') }}" required :error="$errors->first('rosterEffectiveFrom')" />
                            <x-ui.input id="attendance-roster-effective-to" type="date" wire:model="rosterEffectiveTo" label="{{ __('Ends on') }}" :error="$errors->first('rosterEffectiveTo')" />
                        </div>

                        <x-ui.select id="attendance-roster-publish-state" wire:model="rosterPublishState" label="{{ __('Save as') }}" :error="$errors->first('rosterPublishState')">
                            <option value="draft">{{ __('Draft') }}</option>
                            <option value="published">{{ __('Published') }}</option>
                        </x-ui.select>
                    </div>

                    <div class="mt-4 border-t border-border-default pt-4">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <x-ui.button type="button" variant="secondary" class="justify-center" wire:click="validateRosterDraft">
                                <x-icon name="heroicon-o-check-circle" class="h-4 w-4" />
                                {{ __('Validate') }}
                            </x-ui.button>
                            <x-ui.button type="submit" variant="primary" class="justify-center" :disabled="! $canManage || $rosterIncomplete || $selectedEmployeeCount === 0">
                                <x-icon name="heroicon-o-calendar-days" class="h-4 w-4" />
                                {{ __('Save roster assignments') }}
                            </x-ui.button>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-border-default p-card-inner">
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Plain-language checklist') }}</div>
                    <ul class="mt-3 space-y-2 text-sm text-muted">
                        <li class="flex gap-2"><x-icon name="heroicon-o-check-circle" class="mt-0.5 h-4 w-4 text-accent" /> <span>{{ __('Filter first: production, office, department, cost center, or supervisor team.') }}</span></li>
                        <li class="flex gap-2"><x-icon name="heroicon-o-check-circle" class="mt-0.5 h-4 w-4 text-accent" /> <span>{{ __('Shift answers what time they work; pattern answers how that repeats.') }}</span></li>
                        <li class="flex gap-2"><x-icon name="heroicon-o-check-circle" class="mt-0.5 h-4 w-4 text-accent" /> <span>{{ __('Published rosters are used by attendance resolution; drafts are safe to prepare.') }}</span></li>
                    </ul>
                </div>

                <div class="rounded-2xl border border-border-default p-card-inner">
                    <h3 class="text-base font-semibold text-ink">{{ __('Operations') }}</h3>
                    <div class="mt-3 space-y-3">
                        <x-ui.input id="attendance-roster-required-per-shift" type="number" min="0" wire:model.live="rosterRequiredPerShift" label="{{ __('Required per shift') }}" help="{{ __('Optional coverage target used for shortage warnings.') }}" />
                        <x-ui.button type="button" variant="secondary" class="w-full justify-center" wire:click="copyPreviousPeriod">{{ __('Copy previous period') }}</x-ui.button>
                        <x-ui.button type="button" variant="secondary" class="w-full justify-center" wire:click="undoLastDraftRosterOperation">{{ __('Undo latest draft bulk action') }}</x-ui.button>
                    </div>
                </div>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        @include('livewire.people.attendance.partials.rosters-grid', [
            'showPreviewLegend' => true,
            'gridIntro' => __('Scan the filtered employees across the selected date range. Existing assignments show draft or published state; selected unsaved work appears as a preview.'),
        ])
    </x-ui.card>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
        <x-ui.card>
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-ink">{{ __('Coverage and validation') }}</h3>
                    <p class="mt-1 text-sm text-muted">{{ __('Review shortages, overlap warnings, and missing assignment inputs before publish.') }}</p>
                </div>
                @if ($rosterValidationRan)
                    <x-ui.badge variant="{{ collect($rosterValidationFindings)->where('severity', 'error')->isEmpty() ? 'success' : 'danger' }}">{{ __('Validated') }}</x-ui.badge>
                @endif
            </div>

            <div class="mt-4">
                @if (empty($rosterCoverageMatrix['shifts']) || empty($rosterCoverageMatrix['dates']))
                    <p class="text-sm text-muted">{{ __('No coverage rows yet. Save or preview roster assignments to populate coverage.') }}</p>
                @else
                    <div class="overflow-x-auto rounded-2xl border border-border-default">
                        <table class="min-w-full divide-y divide-border-default text-xs">
                            <thead class="bg-surface-subtle/80">
                                <tr>
                                    <th class="sticky left-0 z-10 min-w-24 bg-surface-subtle/95 px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Shift') }}</th>
                                    @foreach ($rosterCoverageMatrix['dates'] as $dateKey)
                                        <th class="min-w-16 px-1.5 py-table-header-y text-center text-[11px] font-semibold uppercase tracking-wider text-muted" wire:key="coverage-date-{{ $dateKey }}">
                                            {{ \Carbon\CarbonImmutable::parse($dateKey)->format('M j') }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-default bg-surface-card">
                                @foreach ($rosterCoverageMatrix['shifts'] as $shiftKey)
                                    <tr wire:key="coverage-shift-{{ $shiftKey }}">
                                        <td class="sticky left-0 z-10 bg-surface-card px-table-cell-x py-1.5 align-middle font-medium text-ink">{{ $shiftKey }}</td>
                                        @foreach ($rosterCoverageMatrix['dates'] as $dateKey)
                                            @php($cell = $rosterCoverageMatrix['cells'][$shiftKey][$dateKey] ?? null)
                                            @php($bgClass = match ($cell['severity'] ?? 'empty') {
                                                'shortage' => 'bg-status-danger-subtle',
                                                'surplus' => 'bg-status-info-subtle',
                                                'met' => 'bg-status-success-subtle',
                                                'neutral' => 'bg-surface-subtle/50',
                                                default => '',
                                            })
                                            @php($inkClass = match ($cell['severity'] ?? 'empty') {
                                                'shortage' => 'text-status-danger',
                                                'surplus' => 'text-status-info',
                                                'met' => 'text-status-success',
                                                default => 'text-muted',
                                            })
                                            <td class="{{ $bgClass }} px-1.5 py-1.5 text-center" title="{{ $cell ? __(':assigned of :required assigned', ['assigned' => $cell['assigned'], 'required' => $cell['required'] ?: '—']) : __('No assignments') }}" wire:key="coverage-cell-{{ $shiftKey }}-{{ $dateKey }}">
                                                @if ($cell)
                                                    <span class="text-[12px] font-semibold tabular-nums {{ $inkClass }}">{{ $cell['assigned'] }}@if ($cell['required'] > 0)<span class="text-muted">/{{ $cell['required'] }}</span>@endif</span>
                                                @else
                                                    <span class="text-muted">·</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="mt-4 space-y-2">
                @forelse ($rosterValidationFindings as $finding)
                    <x-ui.alert :variant="$finding['severity'] === 'error' ? 'danger' : 'warning'">
                        {{ $finding['message'] }}
                    </x-ui.alert>
                @empty
                    <x-ui.alert variant="success">{{ __('No validation findings for the current roster view.') }}</x-ui.alert>
                @endforelse
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <x-ui.button type="button" variant="secondary" wire:click="acceptRosterWarnings">{{ __('Accept warnings') }}</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-base font-semibold text-ink">{{ __('Publish review') }}</h3>
            <p class="mt-1 text-sm text-muted">{{ __('Publish reviewed draft rows for the selected period and queue employee notification intents.') }}</p>
            <div class="mt-4 space-y-3">
                <x-ui.input id="attendance-roster-revision-note" wire:model="rosterRevisionNote" label="{{ __('Revision note') }}" :error="$errors->first('rosterRevisionNote')" />
                <x-ui.button type="button" variant="primary" wire:click="publishReviewedRosters">{{ __('Publish reviewed drafts') }}</x-ui.button>
            </div>

            <div class="mt-6 border-t border-border-default pt-4">
                <h4 class="text-sm font-semibold text-ink">{{ __('Swap shifts') }}</h4>
                <div class="mt-3 grid gap-3 md:grid-cols-3">
                    <x-ui.select wire:model="swapFromEmployeeId" label="{{ __('From') }}">
                        <option value="">{{ __('Choose') }}</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->full_name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select wire:model="swapToEmployeeId" label="{{ __('To') }}">
                        <option value="">{{ __('Choose') }}</option>
                        @foreach ($employees as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->full_name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input type="date" wire:model="swapDate" label="{{ __('Date') }}" :error="$errors->first('swapDate')" />
                </div>
                <x-ui.button type="button" variant="secondary" class="mt-3" wire:click="swapRosterCells">{{ __('Swap') }}</x-ui.button>
            </div>
        </x-ui.card>
    </div>

</div>
