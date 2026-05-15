<div class="space-y-4">
    <x-ui.card>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-2xl">
                <h2 class="text-base font-semibold text-ink">{{ __('Roster Builder') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Choose who is working, which shift they follow, and which policy explains the rules. BLB will use this when attendance days are created.') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button as="a" variant="secondary" href="{{ route('people.attendance.shifts') }}">{{ __('Set up shifts') }}</x-ui.button>
                <x-ui.button as="a" variant="secondary" href="{{ route('people.attendance.policy-studio.library') }}">{{ __('Set up policies') }}</x-ui.button>
                <x-ui.badge variant="info">{{ __('Supervisor workspace') }}</x-ui.badge>
            </div>
        </div>

        @php($rosterIncomplete = $employees->isEmpty() || $shiftTemplates->isEmpty() || $policyGroups->isEmpty())

        @if ($rosterIncomplete)
            <x-ui.alert variant="warning" class="mt-4">
                {{ __('Roster setup needs employees, shifts, and policy groups. Use the setup links above to complete missing pieces before publishing rosters.') }}
            </x-ui.alert>
        @endif

        <form wire:submit="saveRosterAssignment" class="mt-4 space-y-4">
            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.45fr)]">
                <div class="space-y-4 rounded-2xl border border-border-default p-card-inner">
                    <div class="grid gap-4 md:grid-cols-2">
                        <x-ui.select id="attendance-roster-employee" wire:model="rosterEmployeeId" label="{{ __('Who is this for?') }}" required :error="$errors->first('rosterEmployeeId')">
                            <option value="">{{ __('Choose employee') }}</option>
                            @foreach ($employees as $employee)
                                <option value="{{ $employee->id }}">{{ $employee->full_name }} - {{ $employee->employee_number }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.select id="attendance-roster-pattern" wire:model="rosterPatternId" label="{{ __('Repeat pattern') }}" :error="$errors->first('rosterPatternId')">
                            <option value="">{{ __('No pattern - use fixed shift') }}</option>
                            @foreach ($rosterPatterns as $pattern)
                                <option value="{{ $pattern->id }}">{{ $pattern->code }} - {{ $pattern->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
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
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <x-ui.input id="attendance-roster-effective-from" type="date" wire:model="rosterEffectiveFrom" label="{{ __('Starts on') }}" required :error="$errors->first('rosterEffectiveFrom')" />
                        <x-ui.input id="attendance-roster-effective-to" type="date" wire:model="rosterEffectiveTo" label="{{ __('Ends on') }}" :error="$errors->first('rosterEffectiveTo')" />
                        <x-ui.select id="attendance-roster-publish-state" wire:model="rosterPublishState" label="{{ __('Save as') }}" :error="$errors->first('rosterPublishState')">
                            <option value="draft">{{ __('Draft') }}</option>
                            <option value="published">{{ __('Published') }}</option>
                        </x-ui.select>
                    </div>
                </div>

                <div class="space-y-3 rounded-2xl border border-border-default p-card-inner">
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Plain-language checklist') }}</div>
                    <ul class="space-y-2 text-sm text-muted">
                        <li class="flex gap-2"><x-icon name="heroicon-o-check-circle" class="mt-0.5 h-4 w-4 text-accent" /> <span>{{ __('Shift answers: what time do they work?') }}</span></li>
                        <li class="flex gap-2"><x-icon name="heroicon-o-check-circle" class="mt-0.5 h-4 w-4 text-accent" /> <span>{{ __('Policy answers: how do lateness, overtime, and allowances behave?') }}</span></li>
                        <li class="flex gap-2"><x-icon name="heroicon-o-check-circle" class="mt-0.5 h-4 w-4 text-accent" /> <span>{{ __('Published rosters are used by attendance resolution; drafts are safe to prepare.') }}</span></li>
                    </ul>
                </div>
            </div>

            <div class="flex justify-end">
                <x-ui.button type="submit" variant="primary" :disabled="! $canManage || $rosterIncomplete">
                    <x-icon name="heroicon-o-calendar-days" class="h-4 w-4" />
                    {{ __('Save roster assignment') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

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
                                <div class="font-medium text-ink">{{ $pattern->code }} - {{ $pattern->name }}</div>
                                <div class="mt-1 text-xs text-muted">{{ __('Type: :type', ['type' => $this->statusLabel($pattern->pattern_type)]) }}</div>
                            </div>
                            <x-ui.badge :variant="$pattern->status === 'published' ? 'success' : 'warning'">{{ $this->statusLabel($pattern->status) }}</x-ui.badge>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach (($pattern->pattern_definition['days'] ?? []) as $day)
                                <span class="rounded-full bg-surface-subtle px-2.5 py-1 text-xs text-muted" wire:key="roster-pattern-day-{{ $pattern->id }}-{{ $loop->index }}">
                                    {{ __('Day :day: :shift', ['day' => ((int) ($day['offset'] ?? 0)) + 1, 'shift' => $day['shift_code'] ?? '-']) }}
                                </span>
                            @endforeach
                            @foreach (($pattern->pattern_definition['weekdays'] ?? []) as $weekday => $day)
                                <span class="rounded-full bg-surface-subtle px-2.5 py-1 text-xs text-muted" wire:key="roster-pattern-weekday-{{ $pattern->id }}-{{ $weekday }}">
                                    {{ __(':day: :shift', ['day' => __(ucfirst((string) $weekday)), 'shift' => $day['shift_code'] ?? '-']) }}
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
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-base font-semibold text-ink">{{ __('Assignment review') }}</h3>
                <span class="text-xs text-muted">{{ __('What supervisors publish') }}</span>
            </div>

            <div class="mt-4 space-y-3">
                @forelse ($rosterAssignments as $assignment)
                    <div class="rounded-2xl border border-border-default p-3" wire:key="roster-assignment-{{ $assignment->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-medium text-ink">{{ $assignment->employee?->full_name ?? __('Cohort default') }}</div>
                                <div class="mt-1 text-xs text-muted">{{ __('Pattern: :pattern / Shift: :shift / Policy: :policy', ['pattern' => $assignment->rosterPattern?->code ?? '-', 'shift' => $assignment->shiftTemplate?->code ?? '-', 'policy' => $assignment->policyGroup?->code ?? '-']) }}</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-ui.badge :variant="$assignment->publish_state === 'published' ? 'success' : 'warning'">{{ $this->statusLabel($assignment->publish_state) }}</x-ui.badge>
                                <x-ui.button type="button" size="sm" variant="danger" wire:click="deleteRosterAssignment({{ $assignment->id }})" wire:confirm="{{ __('Delete this roster assignment?') }}">{{ __('Delete') }}</x-ui.button>
                            </div>
                        </div>
                        <div class="mt-3 grid gap-2 text-xs text-muted sm:grid-cols-3">
                            <div>{{ __('From: :date', ['date' => $assignment->effective_from?->format('Y-m-d') ?? '-']) }}</div>
                            <div>{{ __('To: :date', ['date' => $assignment->effective_to?->format('Y-m-d') ?? __('Open ended')]) }}</div>
                            <div>{{ __('Revision: :revision', ['revision' => $assignment->revision]) }}</div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-muted">{{ __('No roster assignments configured yet. Supervisors will eventually create drafts here, preview coverage, and publish clean rosters.') }}</p>
                @endforelse
            </div>
        </x-ui.card>
    </div>
</div>
