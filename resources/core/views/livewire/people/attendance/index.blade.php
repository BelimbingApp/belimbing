<?php

use App\Modules\People\Attendance\Livewire\Index;

/** @var Index $this */
?>

<div>
    <x-slot name="title">{{ $surfaceTitle }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$surfaceTitle" :subtitle="$surfaceSubtitle">
            <x-slot name="actions">
                @if ($surface === 'settings' && $section === 'policies' && $policyStudioMode === 'library')
                    <x-ui.button as="a" variant="primary" href="{{ route('people.attendance.policy-studio.builder') }}">
                        <x-icon name="heroicon-o-plus-circle" class="h-4 w-4" />
                        {{ __('New policy') }}
                    </x-ui.button>
                @elseif ($surface === 'settings' && $section === 'shift-library')
                    <x-ui.button as="a" variant="secondary" href="{{ route('people.attendance.rosters') }}">
                        {{ __('Open Roster Builder') }}
                    </x-ui.button>
                @endif
            </x-slot>
            <x-slot name="help">
                {{ __('Attendance records raw clock facts separately from resolved attendance days, then hands only finalized facts to Payroll.') }}
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        @if (! $schemaReady)
            <x-ui.alert variant="warning">
                {{ __('Attendance database tables are not installed yet. Run the Attendance migration before using timecards, clock events, overtime, and payroll handoff screens.') }}
            </x-ui.alert>
        @endif

        @if (in_array($surface, ['my', 'operations'], true))
            <div class="grid gap-4 md:grid-cols-4">
                <x-ui.card>
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Attendance Days') }}</div>
                    <div class="mt-2 text-3xl font-semibold tabular-nums text-ink">{{ $attendanceDays->count() }}</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Pending OT') }}</div>
                    <div class="mt-2 text-3xl font-semibold tabular-nums text-ink">{{ $pendingOvertime->count() }}</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Clock Events') }}</div>
                    <div class="mt-2 text-3xl font-semibold tabular-nums text-ink">{{ $clockEvents->count() }}</div>
                </x-ui.card>
                <x-ui.card>
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Policy Groups') }}</div>
                    <div class="mt-2 text-3xl font-semibold tabular-nums text-ink">{{ $policyGroups->count() }}</div>
                </x-ui.card>
            </div>

            <x-ui.card>
                <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-1 flex-col gap-3 sm:flex-row">
                        <x-ui.search-input
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search employee...') }}"
                        />
                        <x-ui.select id="attendance-status" wire:model.live="status">
                            <option value="">{{ __('All statuses') }}</option>
                            @foreach ($statusOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    @if ($surface === 'my' && $canClock)
                        <div class="flex gap-2">
                            <x-ui.button type="button" variant="secondary" wire:click="openOvertimeModal" :disabled="$currentEmployeeId === null || ! $schemaReady">
                                <x-icon name="heroicon-o-plus-circle" class="h-4 w-4" />
                                {{ __('Request OT') }}
                            </x-ui.button>
                            <x-ui.button type="button" variant="primary" wire:click="clock('in')" :disabled="$currentEmployeeId === null || ! $schemaReady">
                                <x-icon name="heroicon-o-arrow-right-on-rectangle" class="h-4 w-4" />
                                {{ __('Clock In') }}
                            </x-ui.button>
                            <x-ui.button type="button" variant="secondary" wire:click="clock('out')" :disabled="$currentEmployeeId === null || ! $schemaReady">
                                <x-icon name="heroicon-o-arrow-left-on-rectangle" class="h-4 w-4" />
                                {{ __('Clock Out') }}
                            </x-ui.button>
                        </div>
                    @endif
                </div>

                @if ($surface === 'my' && $currentEmployeeId === null)
                    <x-ui.alert variant="warning" class="mb-4">{{ __('Your user account is not linked to an employee record, so web clocking is disabled.') }}</x-ui.alert>
                @endif

                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Date') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employee') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Shift') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Worked') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Late') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('OT Candidate') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Exceptions') }}</th>
                                @if ($surface === 'operations' && $canManage)
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-default bg-surface-card">
                            @forelse ($attendanceDays as $day)
                                <tr wire:key="attendance-day-{{ $day->id }}">
                                    <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">{{ $day->attendance_date?->format('Y-m-d') }}</td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="font-medium text-ink">{{ $day->employee?->full_name ?? __('Employee #:id', ['id' => $day->employee_id]) }}</div>
                                        <div class="font-mono text-xs text-muted">{{ $day->employee?->employee_number }}</div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="text-ink">{{ $day->shiftTemplate?->name ?? __('Unassigned') }}</div>
                                        <div class="text-xs text-muted">{{ $day->policyGroup?->code }}</div>
                                    </td>
                                    <td class="px-table-cell-x py-table-cell-y text-right tabular-nums">{{ number_format($day->worked_minutes / 60, 2) }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-right tabular-nums">{{ $day->late_minutes }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-right tabular-nums">{{ number_format($day->overtime_candidate_minutes / 60, 2) }}</td>
                                    <td class="px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$this->statusVariant($day->status)">{{ __(str_replace('_', ' ', ucfirst($day->status))) }}</x-ui.badge></td>
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted">
                                        {{ collect($day->exception_tags ?? [])->map(fn ($tag) => str_replace('_', ' ', $tag))->implode(', ') ?: '-' }}
                                    </td>
                                    @if ($surface === 'operations' && $canManage)
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <div class="flex justify-end gap-2">
                                                @if (in_array($day->status, ['ready_for_review', 'exception_pending'], true))
                                                    <x-ui.button size="sm" type="button" variant="primary" wire:click="finalizeDay({{ $day->id }})">{{ __('Finalize') }}</x-ui.button>
                                                @endif
                                                @if ($day->locked_at === null && $day->status !== 'locked')
                                                    <x-ui.button size="sm" type="button" variant="secondary" wire:click="lockDay({{ $day->id }})">{{ __('Lock') }}</x-ui.button>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $surface === 'operations' && $canManage ? 9 : 8 }}" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No attendance days found.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif

        @if ($surface === 'approvals')
            <x-ui.card>
                <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-ink">{{ __('Overtime Queue') }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ __('Approve submitted requests, reject invalid requests, or queue approved requests into an open payroll run.') }}</p>
                    </div>
                    <x-ui.input id="attendance-decision-reason" wire:model="decisionReason" label="{{ __('Decision note') }}" placeholder="{{ __('Optional') }}" />
                </div>
                <div class="mt-4 overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employee') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Window') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Requested Hours') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Reason') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                                @if ($canApprove)
                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-default bg-surface-card">
                            @forelse ($overtimeRequests as $request)
                                <tr wire:key="attendance-ot-{{ $request->id }}">
                                    <td class="px-table-cell-x py-table-cell-y">{{ $request->employee?->full_name ?? __('Employee #:id', ['id' => $request->employee_id]) }}</td>
                                    <td class="px-table-cell-x py-table-cell-y font-mono text-xs">{{ $request->starts_at?->format('Y-m-d H:i') }} - {{ $request->ends_at?->format('H:i') }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-right tabular-nums">{{ number_format($request->requested_minutes / 60, 2) }}</td>
                                    <td class="px-table-cell-x py-table-cell-y">{{ $request->reason }}</td>
                                    <td class="px-table-cell-x py-table-cell-y"><x-ui.badge>{{ __(str_replace('_', ' ', ucfirst($request->status))) }}</x-ui.badge></td>
                                    @if ($canApprove)
                                        <td class="px-table-cell-x py-table-cell-y">
                                            <div class="flex justify-end gap-2">
                                                @if ($request->status === 'submitted')
                                                    <x-ui.button size="sm" type="button" variant="primary" wire:click="approveOvertime({{ $request->id }})">{{ __('Approve') }}</x-ui.button>
                                                    <x-ui.button size="sm" type="button" variant="danger" wire:click="rejectOvertime({{ $request->id }})">{{ __('Reject') }}</x-ui.button>
                                                @elseif ($request->status === 'approved')
                                                    <x-ui.button size="sm" type="button" variant="primary" wire:click="queueOvertimePayroll({{ $request->id }})">{{ __('Queue Payroll') }}</x-ui.button>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $canApprove ? 6 : 5 }}" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No overtime requests are waiting for action.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif

        @if ($surface === 'operations')
            <div class="grid gap-4 lg:grid-cols-2">
                <x-ui.card>
                    <h2 class="text-base font-semibold text-ink">{{ __('Recent Clock Events') }}</h2>
                    <div class="mt-4 space-y-3">
                        @forelse ($clockEvents as $event)
                            <div class="rounded-lg border border-border-default p-3" wire:key="clock-event-{{ $event->id }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-medium text-ink">{{ $event->employee?->full_name ?? __('Employee #:id', ['id' => $event->employee_id]) }}</div>
                                        <div class="font-mono text-xs text-muted">{{ $event->occurred_at?->format('Y-m-d H:i:s') }} / {{ $event->source }} / {{ $event->event_type }}</div>
                                    </div>
                                    @if ($event->photo_evidence_present)
                                        <x-ui.badge variant="info">{{ __('Photo') }}</x-ui.badge>
                                    @endif
                                </div>
                                <div class="mt-2 text-xs text-muted">{{ __('IP: :ip / Outlet: :outlet / Geofence: :result', ['ip' => $event->ip_address ?? '-', 'outlet' => $event->outlet_label ?? '-', 'result' => $event->geofence_result ?? '-']) }}</div>
                            </div>
                        @empty
                            <p class="text-sm text-muted">{{ __('No clock events captured yet.') }}</p>
                        @endforelse
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <h2 class="text-base font-semibold text-ink">{{ __('Absenteeism Batches') }}</h2>
                    <div class="mt-4 space-y-3">
                        @forelse ($absenceBatches as $batch)
                            <div class="rounded-lg border border-border-default p-3" wire:key="absence-batch-{{ $batch->id }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-medium text-ink">{{ $batch->reference ?? __('Batch #:id', ['id' => $batch->id]) }}</div>
                                        <div class="font-mono text-xs text-muted">{{ $batch->period_starts_on?->format('Y-m-d') }} - {{ $batch->period_ends_on?->format('Y-m-d') }}</div>
                                    </div>
                                    <x-ui.badge>{{ __(str_replace('_', ' ', ucfirst($batch->status))) }}</x-ui.badge>
                                </div>
                                <div class="mt-2 text-xs text-muted">{{ trans_choice(':count candidate|:count candidates', $batch->entries_count, ['count' => $batch->entries_count]) }} / {{ __('Lock date: :date', ['date' => $batch->lock_date?->format('Y-m-d') ?? '-']) }}</div>
                            </div>
                        @empty
                            <p class="text-sm text-muted">{{ __('No absenteeism batches generated yet.') }}</p>
                        @endforelse
                    </div>
                </x-ui.card>
            </div>
        @endif

        @if ($surface === 'settings')
                @if ($section === 'policies')
                    <div class="space-y-4">
                        @if ($policyStudioMode === 'library')
                            <x-ui.card>
                                    <div class="overflow-x-auto -mx-card-inner px-card-inner">
                                        <table class="min-w-full divide-y divide-border-default text-sm">
                                            <thead class="bg-surface-subtle/80">
                                                <tr>
                                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('No.') }}</th>
                                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Policy group') }}</th>
                                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Version') }}</th>
                                                    <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Effective') }}</th>
                                                    <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-border-default bg-surface-card">
                                                @forelse ($policyGroups as $group)
                                                    <tr wire:key="policy-library-row-{{ $group->id }}">
                                                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted tabular-nums">{{ $loop->iteration }}</td>
                                                        <td class="px-table-cell-x py-table-cell-y">
                                                            <button type="button" class="text-left font-medium text-accent hover:underline" wire:click="editPolicyGroup({{ $group->id }})">{{ $group->name }}</button>
                                                            <div class="font-mono text-xs text-muted">{{ $group->code }}</div>
                                                        </td>
                                                        <td class="px-table-cell-x py-table-cell-y">
                                                            <x-ui.button type="button" size="sm" :variant="$group->status === 'active' ? 'primary' : 'secondary'" wire:click="togglePolicyStatus({{ $group->id }})">{{ __(ucfirst($group->status)) }}</x-ui.button>
                                                        </td>
                                                        <td class="px-table-cell-x py-table-cell-y tabular-nums">{{ $group->version }}</td>
                                                        <td class="px-table-cell-x py-table-cell-y text-xs text-muted">{{ $group->effective_from?->format('Y-m-d') ?? '-' }}</td>
                                                        <td class="px-table-cell-x py-table-cell-y">
                                                            <div class="flex justify-end gap-2">
                                                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="simulatePolicyGroup({{ $group->id }})">{{ __('Simulate') }}</x-ui.button>
                                                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="duplicatePolicyGroup({{ $group->id }})">{{ __('Duplicate') }}</x-ui.button>
                                                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="exportPolicyGroupTemplate({{ $group->id }})">{{ __('Download') }}</x-ui.button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                @empty
                                                    <tr><td colspan="6" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No policy groups yet. Start from a template or create a new policy.') }}</td></tr>
                                                @endforelse
                                            </tbody>
                                        </table>
                                    </div>
                            </x-ui.card>

                            @if ($policyTemplateExportJson !== '')
                                <x-ui.card>
                                    <h3 class="text-base font-semibold text-ink">{{ __('Template JSON ready') }}</h3>
                                    <p class="mt-1 text-sm text-muted">{{ __('Copy this JSON into a shared template repository or country pack. Upload it from Policy Builder when needed.') }}</p>
                                    <x-ui.textarea id="attendance-policy-library-template-export" wire:model="policyTemplateExportJson" label="{{ __('Template JSON') }}" rows="10" class="mt-4" />
                                </x-ui.card>
                            @endif
                        @endif

                        @if ($policyStudioMode === 'builder')
                        <x-ui.template-picker
                            :templates="$policyTemplates"
                            :selected-key="$selectedPolicyTemplateKey"
                            :show-all="$showAllPolicyTemplates"
                            select-action="usePolicyTemplate"
                            upload-action="$set('showPolicyTemplateImportModal', true)"
                        />

                        @if ($showPolicyBuilderForm)
                            <form wire:submit="savePolicyGroup" class="space-y-4">
                                <x-ui.card>
                                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <h2 class="text-base font-semibold text-ink">{{ __('Identification') }}</h2>
                                            <p class="mt-1 text-sm text-muted">{{ __('How this policy appears in rosters, imports and audit logs.') }}</p>
                                        </div>
                                        @if ($editingPolicyGroupId !== null)
                                            <x-ui.button type="button" variant="secondary" wire:click="cancelPolicyEdit">{{ __('Cancel edit') }}</x-ui.button>
                                        @endif
                                    </div>
                                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                                        <x-ui.input id="attendance-policy-code" wire:model="policyCode" label="{{ __('Policy code') }}" placeholder="{{ __('STD_8_5') }}" required help="{{ __('Short reference used in rosters and imports.') }}" :error="$errors->first('policyCode')" />
                                        <x-ui.input id="attendance-policy-name" wire:model="policyName" label="{{ __('Policy name') }}" placeholder="{{ __('Standard 8 to 5') }}" required help="{{ __('Human-readable name for this attendance rulebook.') }}" :error="$errors->first('policyName')" />
                                    </div>
                                </x-ui.card>

                                <x-ui.card>
                                    <div>
                                        <h2 class="text-base font-semibold text-ink">{{ __('Work time') }}</h2>
                                        <p class="mt-1 text-sm text-muted">{{ __('How raw clock-in/out becomes payable minutes.') }}</p>
                                    </div>
                                    <div class="mt-4 space-y-4">
                                        <x-ui.alert variant="info">
                                            {{ __('Shift start, shift end and break windows are defined in Shift Builder. This policy decides how those scheduled times become payable time, lateness and overtime.') }}
                                            <a href="{{ route('people.attendance.shifts') }}" target="_blank" rel="noopener noreferrer" class="font-medium text-accent hover:underline">{{ __('Open Shift Builder in a new tab') }}</a>
                                        </x-ui.alert>
                                        @if ($shiftTemplates->isNotEmpty())
                                            <div class="rounded-2xl border border-border-default bg-surface-subtle/60 p-card-inner">
                                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                                    <div>
                                                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Shift context') }}</p>
                                                        @php($sampleShift = $shiftTemplates->first())
                                                        <p class="mt-1 text-sm font-medium text-ink">{{ $sampleShift->code }} · {{ $sampleShift->name }}</p>
                                                        <p class="mt-0.5 font-mono text-xs text-muted">{{ $sampleShift->starts_at }} → {{ $sampleShift->ends_at }} · {{ trans_choice(':count punch window|:count punch windows', $sampleShift->punchWindows->count(), ['count' => $sampleShift->punchWindows->count()]) }}</p>
                                                    </div>
                                                    <x-ui.button as="a" variant="secondary" href="{{ route('people.attendance.shifts') }}" target="_blank" rel="noopener noreferrer">
                                                        {{ __('Tune shifts') }}
                                                    </x-ui.button>
                                                </div>
                                            </div>
                                        @endif
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <x-ui.select id="attendance-policy-work-rounding-method" wire:model="policyWorkRoundingMethod" label="{{ __('Daily rounding') }}" help="{{ __('How BLB adjusts raw worked minutes before payable time is calculated.') }}" :error="$errors->first('policyWorkRoundingMethod')">
                                                <option value="none">{{ __('None') }}</option>
                                                <option value="floor">{{ __('Floor') }}</option>
                                                <option value="ceiling">{{ __('Ceiling') }}</option>
                                                <option value="nearest">{{ __('Nearest') }}</option>
                                            </x-ui.select>
                                            <x-ui.input id="attendance-policy-work-rounding-minutes" type="number" min="1" max="60" wire:model="policyWorkRoundingMinutes" label="{{ __('Rounding block') }}" suffix="{{ __('min') }}" help="{{ __('The rounding block, such as 5, 10, or 15 minutes.') }}" :error="$errors->first('policyWorkRoundingMinutes')" />
                                        </div>
                                        <x-ui.checkbox id="attendance-policy-exclude-break" wire:model="policyExcludeBreakFromWork" label="{{ __('Exclude break time from work hours') }}" />
                                        <x-ui.checkbox id="attendance-policy-less-break-lateness" wire:model="policyLessBreakLateness" label="{{ __('Offset lateness by approved break handling') }}" />
                                    </div>
                                </x-ui.card>

                                <x-ui.card>
                                    <div>
                                        <h2 class="text-base font-semibold text-ink">{{ __('Lateness') }}</h2>
                                        <p class="mt-1 text-sm text-muted">{{ __('Grace minutes and how late arrivals affect payable time.') }}</p>
                                    </div>
                                    <div class="mt-4 space-y-4">
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <x-ui.select id="attendance-policy-lateness-rounding-method" wire:model="policyLatenessRoundingMethod" label="{{ __('Daily rounding') }}" help="{{ __('How late minutes are rounded before a deduction is considered.') }}" :error="$errors->first('policyLatenessRoundingMethod')">
                                                <option value="none">{{ __('None') }}</option>
                                                <option value="floor">{{ __('Floor') }}</option>
                                                <option value="ceiling">{{ __('Ceiling') }}</option>
                                                <option value="nearest">{{ __('Nearest') }}</option>
                                            </x-ui.select>
                                            <x-ui.input id="attendance-policy-lateness-rounding-minutes" type="number" min="1" max="60" wire:model="policyLatenessRoundingMinutes" label="{{ __('Rounding block') }}" suffix="{{ __('min') }}" help="{{ __('The lateness rounding block, such as 5 minutes.') }}" :error="$errors->first('policyLatenessRoundingMinutes')" />
                                        </div>
                                        <div class="grid gap-4 sm:grid-cols-4">
                                            <x-ui.input id="attendance-policy-grace-in" type="number" min="0" max="240" wire:model="policyGraceIn" label="{{ __('In grace') }}" suffix="{{ __('min') }}" help="{{ __('Minutes after shift start before clock-in is treated as late.') }}" :error="$errors->first('policyGraceIn')" />
                                            <x-ui.input id="attendance-policy-grace-out" type="number" min="0" max="240" wire:model="policyGraceOut" label="{{ __('Out grace') }}" suffix="{{ __('min') }}" help="{{ __('Minutes before shift end that are tolerated before early-out rules apply.') }}" :error="$errors->first('policyGraceOut')" />
                                            <x-ui.input id="attendance-policy-grace-start-break" type="number" min="0" max="240" wire:model="policyGraceStartBreak" label="{{ __('Break out') }}" suffix="{{ __('min') }}" help="{{ __('Tolerance when employees start break later or earlier than scheduled.') }}" :error="$errors->first('policyGraceStartBreak')" />
                                            <x-ui.input id="attendance-policy-grace-end-break" type="number" min="0" max="240" wire:model="policyGraceEndBreak" label="{{ __('Break in') }}" suffix="{{ __('min') }}" help="{{ __('Tolerance when employees return from break after the expected time.') }}" :error="$errors->first('policyGraceEndBreak')" />
                                        </div>
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            @if ($payrollPayItems->isNotEmpty())
                                                <x-ui.select id="attendance-policy-lateness-pay-item" wire:model="policyLatenessPayItem" label="{{ __('Deduction pay item') }}" help="{{ __('Payroll pay items are the source of truth for attendance payroll codes.') }}" :error="$errors->first('policyLatenessPayItem')">
                                                    <option value="">{{ __('Choose pay item') }}</option>
                                                    @foreach ($payrollPayItems as $payItem)
                                                        <option value="{{ $payItem->code }}">{{ $payItem->code }} — {{ $payItem->name }}</option>
                                                    @endforeach
                                                </x-ui.select>
                                            @else
                                                <x-ui.input id="attendance-policy-lateness-pay-item" wire:model="policyLatenessPayItem" label="{{ __('Deduction pay item') }}" help="{{ __('Create payroll pay items first to get selectable codes here.') }}" :error="$errors->first('policyLatenessPayItem')" />
                                            @endif
                                            <x-ui.input id="attendance-policy-lateness-monthly-minutes" type="number" min="1" max="60" wire:model="policyLatenessMonthlyRoundingMinutes" label="{{ __('Monthly rounding') }}" suffix="{{ __('min') }}" help="{{ __('If payroll deducts lateness monthly, this rounds the monthly total.') }}" :error="$errors->first('policyLatenessMonthlyRoundingMinutes')" />
                                        </div>
                                    </div>
                                </x-ui.card>

                                <x-ui.card>
                                    <div>
                                        <h2 class="text-base font-semibold text-ink">{{ __('Overtime & payroll') }}</h2>
                                        <p class="mt-1 text-sm text-muted">{{ __('When extra minutes become overtime candidates, and which payroll items receive them.') }}</p>
                                    </div>
                                    <div class="mt-4 space-y-4">
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <x-ui.input id="attendance-policy-early-ot-min" type="number" min="0" max="720" wire:model="policyEarlyOvertimeMinimumMinutes" label="{{ __('Before shift') }}" suffix="{{ __('min') }}" help="{{ __('Minimum minutes before shift start before early work becomes an overtime candidate.') }}" :error="$errors->first('policyEarlyOvertimeMinimumMinutes')" />
                                            <x-ui.input id="attendance-policy-late-ot-min" type="number" min="0" max="720" wire:model="policyLateOvertimeMinimumMinutes" label="{{ __('After shift') }}" suffix="{{ __('min') }}" help="{{ __('Minimum minutes after shift end before extra work becomes an overtime candidate.') }}" :error="$errors->first('policyLateOvertimeMinimumMinutes')" />
                                        </div>
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            @foreach ([
                                                'policyNormalOvertimePayItem' => ['attendance-policy-normal-ot-pay-item', __('Normal OT item'), __('Required payroll item for ordinary overtime candidates.')],
                                                'policyExtendedOvertimePayItem' => ['attendance-policy-extended-ot-pay-item', __('Extended OT item'), __('Optional payroll item for a later overtime band.')],
                                                'policyRestDayOvertimePayItem' => ['attendance-policy-rest-day-ot-pay-item', __('Rest day OT item'), __('Optional payroll item when overtime happens on a roster rest day.')],
                                                'policyHolidayOvertimePayItem' => ['attendance-policy-holiday-ot-pay-item', __('Holiday OT item'), __('Optional payroll item when overtime happens on a public holiday.')],
                                            ] as $payItemField => [$payItemId, $payItemLabel, $payItemHelp])
                                                @if ($payrollPayItems->isNotEmpty())
                                                    <x-ui.select :id="$payItemId" wire:model="{{ $payItemField }}" :label="$payItemLabel" :help="$payItemHelp" :error="$errors->first($payItemField)">
                                                        <option value="">{{ __('Choose pay item') }}</option>
                                                        @foreach ($payrollPayItems as $payItem)
                                                            <option value="{{ $payItem->code }}">{{ $payItem->code }} — {{ $payItem->name }}</option>
                                                        @endforeach
                                                    </x-ui.select>
                                                @else
                                                    <x-ui.input :id="$payItemId" wire:model="{{ $payItemField }}" :label="$payItemLabel" help="{{ __('Create payroll pay items first to get selectable codes here.') }}" :error="$errors->first($payItemField)" />
                                                @endif
                                            @endforeach
                                        </div>
                                        <x-ui.input id="attendance-policy-currency" wire:model="policyCurrency" label="{{ __('Payroll currency') }}" required help="{{ __('Three-letter payroll currency code, for example MYR.') }}" :error="$errors->first('policyCurrency')" />
                                    </div>
                                </x-ui.card>

                                <x-ui.card>
                                    <div>
                                        <h2 class="text-base font-semibold text-ink">{{ __('Effective dates & activation') }}</h2>
                                        <p class="mt-1 text-sm text-muted">{{ __('When supervisors can pick this policy, and whether it is currently in use.') }}</p>
                                    </div>
                                    <div class="mt-4 grid gap-4 md:grid-cols-3">
                                        <x-ui.input id="attendance-policy-effective-from" type="date" wire:model="policyEffectiveFrom" label="{{ __('Effective from') }}" required help="{{ __('First date this policy can be assigned to rosters.') }}" :error="$errors->first('policyEffectiveFrom')" />
                                        <x-ui.input id="attendance-policy-effective-to" type="date" wire:model="policyEffectiveTo" label="{{ __('Effective to') }}" help="{{ __('Optional last date this policy can be assigned.') }}" :error="$errors->first('policyEffectiveTo')" />
                                        <x-ui.select id="attendance-policy-status" wire:model="policyStatus" label="{{ __('Status') }}" help="{{ __('Active policies can be used in rosters.') }}" :error="$errors->first('policyStatus')">
                                            <option value="active">{{ __('Active') }}</option>
                                            <option value="inactive">{{ __('Inactive') }}</option>
                                        </x-ui.select>
                                    </div>
                                </x-ui.card>

                                <x-ui.card>
                                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <h2 class="text-base font-semibold text-ink">{{ __('Readiness status') }}</h2>
                                            <p class="mt-1 text-sm font-medium text-ink">{{ __('Ready to publish') }}</p>
                                            <p class="mt-1 text-sm text-muted">{{ __('All required fields are set. Run Validate to test the policy against a sample shift before exposing it to supervisors.') }}</p>
                                        </div>
                                        <x-ui.badge variant="success">{{ __('Ready') }}</x-ui.badge>
                                    </div>
                                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                                        <x-ui.button as="a" variant="secondary" href="{{ route('people.attendance.policy-studio.validator') }}">
                                            {{ __('Validate with sample shift') }}
                                        </x-ui.button>
                                        <x-ui.button type="button" variant="secondary" wire:click="exportBuilderPolicyTemplate" :disabled="! $canManage">
                                            {{ __('Download as JSON') }}
                                        </x-ui.button>
                                        <x-ui.button type="submit" variant="primary" :disabled="! $canManage">
                                            <x-icon name="heroicon-o-shield-check" class="h-4 w-4" />
                                            {{ $editingPolicyGroupId === null ? __('Create and validate policy') : __('Save and validate policy') }}
                                        </x-ui.button>
                                    </div>
                                </x-ui.card>
                            </form>
                        @endif

                        @if ($policyTemplateExportJson !== '')
                            <x-ui.card>
                                <h3 class="text-base font-semibold text-ink">{{ __('Template JSON ready') }}</h3>
                                <p class="mt-1 text-sm text-muted">{{ __('Copy this JSON into a shared template repository or country pack. Upload supports this same format.') }}</p>
                                <x-ui.textarea id="attendance-policy-builder-template-export" wire:model="policyTemplateExportJson" label="{{ __('Template JSON') }}" rows="10" class="mt-4" />
                            </x-ui.card>
                        @endif
                        @endif

                        @if ($policyStudioMode === 'simulate')
                        <x-ui.card>
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <h2 class="text-base font-semibold text-ink">{{ __('Policy Validator') }}</h2>
                                    <p class="mt-1 text-sm text-muted">{{ __('Validate a policy group, then simulate real clock times before the policy is used in rosters.') }}</p>
                                </div>
                                <x-ui.badge variant="info">{{ __('Preview only') }}</x-ui.badge>
                            </div>

                            <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                <div class="space-y-4 rounded-2xl border border-border-default p-card-inner">
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <x-ui.select id="attendance-policy-preview-policy" wire:model="policyPreviewPolicyId" label="{{ __('Policy group') }}" :error="$errors->first('policyPreviewPolicyId')">
                                            <option value="">{{ __('Choose policy') }}</option>
                                            @foreach ($policyGroups as $group)
                                                <option value="{{ $group->id }}">{{ $group->code }} - {{ $group->name }}</option>
                                            @endforeach
                                        </x-ui.select>
                                        <x-ui.select id="attendance-policy-preview-shift" wire:model="policyPreviewShiftId" label="{{ __('Shift template') }}" :error="$errors->first('policyPreviewShiftId')">
                                            <option value="">{{ __('Choose shift') }}</option>
                                            @foreach ($shiftTemplates as $shift)
                                                <option value="{{ $shift->id }}">{{ $shift->code }} - {{ $shift->name }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    </div>

                                    <div class="grid gap-4 md:grid-cols-3">
                                        <x-ui.input id="attendance-policy-preview-date" type="date" wire:model="policyPreviewDate" label="{{ __('Date') }}" :error="$errors->first('policyPreviewDate')" />
                                        <x-ui.input id="attendance-policy-preview-in" type="time" wire:model="policyPreviewClockIn" label="{{ __('Clock in') }}" :error="$errors->first('policyPreviewClockIn')" />
                                        <x-ui.input id="attendance-policy-preview-out" type="time" wire:model="policyPreviewClockOut" label="{{ __('Clock out') }}" :error="$errors->first('policyPreviewClockOut')" />
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        <x-ui.button type="button" variant="secondary" wire:click="validatePolicyPreview">
                                            <x-icon name="heroicon-o-shield-check" class="h-4 w-4" />
                                            {{ __('Validate policy') }}
                                        </x-ui.button>
                                        <x-ui.button type="button" variant="primary" wire:click="simulatePolicyPreview">
                                            <x-icon name="heroicon-o-play-circle" class="h-4 w-4" />
                                            {{ __('Simulate day') }}
                                        </x-ui.button>
                                    </div>
                                </div>

                                <div class="space-y-3 rounded-2xl border border-border-default p-card-inner">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('What this protects') }}</div>
                                    <ul class="space-y-2 text-sm text-muted">
                                        <li class="flex gap-2"><x-icon name="heroicon-o-check-circle" class="mt-0.5 h-4 w-4 text-accent" /> <span>{{ __('Policy findings use stable codes, so imports and operators can rely on them.') }}</span></li>
                                        <li class="flex gap-2"><x-icon name="heroicon-o-check-circle" class="mt-0.5 h-4 w-4 text-accent" /> <span>{{ __('Simulation does not create attendance records or payroll inputs.') }}</span></li>
                                        <li class="flex gap-2"><x-icon name="heroicon-o-check-circle" class="mt-0.5 h-4 w-4 text-accent" /> <span>{{ __('Overtime remains a candidate until approved by workflow.') }}</span></li>
                                    </ul>
                                </div>
                            </div>
                        </x-ui.card>

                        <div class="grid gap-4 xl:grid-cols-2">
                            <x-ui.card>
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-base font-semibold text-ink">{{ __('Validation findings') }}</h3>
                                    @if ($this->policyValidationResult)
                                        <x-ui.badge :variant="$this->policyValidationResult['status'] === 'error' ? 'danger' : ($this->policyValidationResult['status'] === 'warning' ? 'warning' : 'success')">{{ __(ucfirst($this->policyValidationResult['status'])) }}</x-ui.badge>
                                    @endif
                                </div>
                                <div class="mt-4 space-y-3">
                                    @if (! $this->policyValidationResult)
                                        <p class="text-sm text-muted">{{ __('Choose a policy group and run validation to see setup issues before activation.') }}</p>
                                    @elseif (empty($this->policyValidationResult['findings']))
                                        <x-ui.alert variant="success">{{ __('No validation findings for this policy group.') }}</x-ui.alert>
                                    @else
                                        @foreach ($this->policyValidationResult['findings'] as $finding)
                                            <div class="rounded-2xl border border-border-default p-3" wire:key="policy-finding-{{ $loop->index }}">
                                                <div class="flex items-start justify-between gap-3">
                                                    <div class="font-mono text-xs text-muted">{{ $finding['code'] }}</div>
                                                    <x-ui.badge :variant="$finding['severity'] === 'error' ? 'danger' : ($finding['severity'] === 'warning' ? 'warning' : 'info')">{{ __(ucfirst($finding['severity'])) }}</x-ui.badge>
                                                </div>
                                                <p class="mt-2 text-sm text-ink">{{ $finding['message'] }}</p>
                                                <p class="mt-1 font-mono text-xs text-muted">{{ $finding['path'] }}</p>
                                            </div>
                                        @endforeach
                                    @endif
                                </div>
                            </x-ui.card>

                            <x-ui.card>
                                <div class="flex items-center justify-between gap-3">
                                    <h3 class="text-base font-semibold text-ink">{{ __('Simulation preview') }}</h3>
                                    @if ($this->policySimulationResult)
                                        <x-ui.badge :variant="$this->policySimulationResult['status'] === 'ok' ? 'success' : 'warning'">{{ __(ucfirst($this->policySimulationResult['status'])) }}</x-ui.badge>
                                    @endif
                                </div>
                                @if (! $this->policySimulationResult)
                                    <p class="mt-4 text-sm text-muted">{{ __('Run a simulation to preview lateness, payable minutes, overtime candidates, and allowance candidates.') }}</p>
                                @elseif (($this->policySimulationResult['status'] ?? null) === 'error')
                                    <div class="mt-4 space-y-3">
                                        @foreach ($this->policySimulationResult['findings'] as $finding)
                                            <x-ui.alert variant="danger" wire:key="simulation-error-{{ $loop->index }}">{{ $finding['message'] }}</x-ui.alert>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                        @foreach ([
                                            __('Worked') => $this->policySimulationResult['metrics']['worked_minutes'],
                                            __('Payable') => $this->policySimulationResult['metrics']['payable_minutes'],
                                            __('Late') => $this->policySimulationResult['metrics']['late_minutes'],
                                            __('OT candidate') => $this->policySimulationResult['metrics']['overtime_candidate_minutes'],
                                        ] as $label => $minutes)
                                            <div class="rounded-2xl border border-border-default p-3">
                                                <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ $label }}</div>
                                                <div class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ number_format($minutes / 60, 2) }}h</div>
                                                <div class="text-xs text-muted">{{ trans_choice(':count minute|:count minutes', $minutes, ['count' => $minutes]) }}</div>
                                            </div>
                                        @endforeach
                                    </div>
                                    <p class="mt-4 text-sm text-muted">{{ $this->policySimulationResult['explanation'] }}</p>
                                    <div class="mt-4">
                                        <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Allowance candidates') }}</div>
                                        <div class="mt-2 space-y-2">
                                            @forelse ($this->policySimulationResult['allowance_candidates'] as $candidate)
                                                <div class="rounded-2xl border border-border-default p-3" wire:key="allowance-candidate-{{ $candidate['code'] }}">
                                                    <div class="font-medium text-ink">{{ $candidate['code'] }} - {{ $candidate['name'] }}</div>
                                                    <div class="mt-1 text-xs text-muted">{{ __('Pay item: :code / Matched rows: :count', ['code' => $candidate['payroll_pay_item_code'] ?? '-', 'count' => count($candidate['matched_rows'])]) }}</div>
                                                </div>
                                            @empty
                                                <p class="text-sm text-muted">{{ __('No daily allowance candidates matched this simulation.') }}</p>
                                            @endforelse
                                        </div>
                                    </div>
                                @endif
                            </x-ui.card>
                        </div>
                        @endif
                    </div>
                @endif

                @if ($section === 'shifts')
                    <x-ui.template-picker
                        :templates="$shiftTemplatePresets"
                        :selected-key="$selectedShiftTemplateKey"
                        :show-all="$showAllShiftTemplates"
                        select-action="useShiftTemplate"
                        upload-action="$set('showShiftTemplateImportModal', true)"
                    />

                    @if ($showShiftBuilderForm)
                        <form wire:submit="saveShiftTemplate" class="space-y-4">
                            <x-ui.card>
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <h2 class="text-base font-semibold text-ink">{{ __('Identification') }}</h2>
                                        <p class="mt-1 text-sm text-muted">{{ __('How this shift appears in rosters, policy validation and audit logs.') }}</p>
                                    </div>
                                    @if ($editingShiftTemplateId !== null)
                                        <x-ui.button type="button" variant="secondary" wire:click="cancelShiftEdit">{{ __('Cancel edit') }}</x-ui.button>
                                    @endif
                                </div>
                                <div class="mt-4 grid gap-4 md:grid-cols-2">
                                    <x-ui.input id="attendance-shift-code" wire:model="shiftCode" label="{{ __('Shift code') }}" placeholder="{{ __('OFFICE_DAY') }}" required help="{{ __('Short reference supervisors see while building rosters.') }}" :error="$errors->first('shiftCode')" />
                                    <x-ui.input id="attendance-shift-name" wire:model="shiftName" label="{{ __('Shift name') }}" placeholder="{{ __('Office day') }}" required help="{{ __('Human-readable name for this scheduled work pattern.') }}" :error="$errors->first('shiftName')" />
                                </div>
                            </x-ui.card>

                            <x-ui.card>
                                <div>
                                    <h2 class="text-base font-semibold text-ink">{{ __('Work schedule') }}</h2>
                                    <p class="mt-1 text-sm text-muted">{{ __('The normal start, end, break and expected payable work time for this shift.') }}</p>
                                </div>
                                <div class="mt-4 space-y-4">
                                    <x-ui.alert variant="info">
                                        {{ __('Policy Builder decides rounding, lateness and overtime. Shift Builder only defines scheduled time and punch expectations.') }}
                                        <a href="{{ route('people.attendance.policy-studio.builder') }}" target="_blank" rel="noopener noreferrer" class="font-medium text-accent hover:underline">{{ __('Open Policy Builder in a new tab') }}</a>
                                    </x-ui.alert>
                                    <div class="grid gap-4 sm:grid-cols-3">
                                        <x-ui.input id="attendance-shift-starts-at" type="time" wire:model="shiftStartsAt" label="{{ __('Shift start') }}" required help="{{ __('When scheduled work begins.') }}" :error="$errors->first('shiftStartsAt')" />
                                        <x-ui.input id="attendance-shift-ends-at" type="time" wire:model="shiftEndsAt" label="{{ __('Shift end') }}" required help="{{ __('Use an earlier end time for overnight shifts.') }}" :error="$errors->first('shiftEndsAt')" />
                                        <x-ui.input id="attendance-shift-expected-work-minutes" type="number" min="1" max="1440" wire:model="shiftExpectedWorkMinutes" label="{{ __('Expected work') }}" suffix="{{ __('min') }}" required help="{{ __('Payable work time before policy rounding or exceptions.') }}" :error="$errors->first('shiftExpectedWorkMinutes')" />
                                    </div>
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <x-ui.input id="attendance-shift-break-starts-at" type="time" wire:model="shiftBreakStartsAt" label="{{ __('Break start') }}" help="{{ __('Leave blank with break end if this shift has no scheduled break.') }}" :error="$errors->first('shiftBreakStartsAt')" />
                                        <x-ui.input id="attendance-shift-break-ends-at" type="time" wire:model="shiftBreakEndsAt" label="{{ __('Break end') }}" help="{{ __('Scheduled return time from the main break.') }}" :error="$errors->first('shiftBreakEndsAt')" />
                                    </div>
                                </div>
                            </x-ui.card>

                            <x-ui.card>
                                <div>
                                    <h2 class="text-base font-semibold text-ink">{{ __('Punch windows') }}</h2>
                                    <p class="mt-1 text-sm text-muted">{{ __('How early or late BLB should accept clock events around shift start and end.') }}</p>
                                </div>
                                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                    <x-ui.input id="attendance-shift-in-before" type="number" min="0" max="720" wire:model="shiftInWindowBeforeMinutes" label="{{ __('Clock-in before') }}" suffix="{{ __('min') }}" help="{{ __('How early before shift start a clock-in can match this shift.') }}" :error="$errors->first('shiftInWindowBeforeMinutes')" />
                                    <x-ui.input id="attendance-shift-in-after" type="number" min="0" max="720" wire:model="shiftInWindowAfterMinutes" label="{{ __('Clock-in after') }}" suffix="{{ __('min') }}" help="{{ __('How late after shift start a clock-in can still match this shift.') }}" :error="$errors->first('shiftInWindowAfterMinutes')" />
                                    <x-ui.input id="attendance-shift-out-before" type="number" min="0" max="720" wire:model="shiftOutWindowBeforeMinutes" label="{{ __('Clock-out before') }}" suffix="{{ __('min') }}" help="{{ __('How early before shift end a clock-out can match this shift.') }}" :error="$errors->first('shiftOutWindowBeforeMinutes')" />
                                    <x-ui.input id="attendance-shift-out-after" type="number" min="0" max="720" wire:model="shiftOutWindowAfterMinutes" label="{{ __('Clock-out after') }}" suffix="{{ __('min') }}" help="{{ __('How late after shift end a clock-out can match this shift.') }}" :error="$errors->first('shiftOutWindowAfterMinutes')" />
                                </div>
                            </x-ui.card>

                            <x-ui.card>
                                <div>
                                    <h2 class="text-base font-semibold text-ink">{{ __('Effective dates & activation') }}</h2>
                                    <p class="mt-1 text-sm text-muted">{{ __('When supervisors can pick this shift, and how overnight payroll dates are attributed.') }}</p>
                                </div>
                                <div class="mt-4 grid gap-4 md:grid-cols-4">
                                    <x-ui.input id="attendance-shift-effective-from" type="date" wire:model="shiftEffectiveFrom" label="{{ __('Effective from') }}" required help="{{ __('First date this shift can be assigned in rosters.') }}" :error="$errors->first('shiftEffectiveFrom')" />
                                    <x-ui.input id="attendance-shift-effective-to" type="date" wire:model="shiftEffectiveTo" label="{{ __('Effective to') }}" help="{{ __('Optional last date this shift can be assigned.') }}" :error="$errors->first('shiftEffectiveTo')" />
                                    <x-ui.select id="attendance-shift-payroll-attribution" wire:model="shiftPayrollAttribution" label="{{ __('Payroll date') }}" help="{{ __('Which date receives attendance and payroll attribution for overnight shifts.') }}" :error="$errors->first('shiftPayrollAttribution')">
                                        <option value="shift_start_date">{{ __('Shift start date') }}</option>
                                        <option value="shift_end_date">{{ __('Shift end date') }}</option>
                                    </x-ui.select>
                                    <x-ui.select id="attendance-shift-status" wire:model="shiftStatus" label="{{ __('Status') }}" help="{{ __('Active shifts can be used in rosters.') }}" :error="$errors->first('shiftStatus')">
                                        <option value="active">{{ __('Active') }}</option>
                                        <option value="inactive">{{ __('Inactive') }}</option>
                                    </x-ui.select>
                                </div>
                            </x-ui.card>

                            <x-ui.card>
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                    <div>
                                        <h2 class="text-base font-semibold text-ink">{{ __('Readiness status') }}</h2>
                                        <p class="mt-1 text-sm font-medium text-ink">{{ __('Ready to publish') }}</p>
                                        <p class="mt-1 text-sm text-muted">{{ __('All required fields are set. Save this shift, then validate a policy against it before supervisors use it in rosters.') }}</p>
                                    </div>
                                    <x-ui.badge variant="success">{{ __('Ready') }}</x-ui.badge>
                                </div>
                                <div class="mt-4 flex flex-wrap justify-end gap-2">
                                    <x-ui.button as="a" variant="secondary" href="{{ route('people.attendance.policy-studio.validator') }}">
                                        {{ __('Validate with policy') }}
                                    </x-ui.button>
                                    <x-ui.button type="button" variant="secondary" wire:click="exportBuilderShiftTemplate" :disabled="! $canManage">
                                        {{ __('Download as JSON') }}
                                    </x-ui.button>
                                    <x-ui.button type="submit" variant="primary" :disabled="! $canManage">
                                        <x-icon name="heroicon-o-shield-check" class="h-4 w-4" />
                                        {{ $editingShiftTemplateId === null ? __('Create shift') : __('Save shift') }}
                                    </x-ui.button>
                                </div>
                            </x-ui.card>
                        </form>
                    @endif

                    @if ($shiftTemplateExportJson !== '')
                        <x-ui.card>
                            <h3 class="text-base font-semibold text-ink">{{ __('Template JSON ready') }}</h3>
                            <p class="mt-1 text-sm text-muted">{{ __('Copy this JSON into a shared template repository or country pack. Upload supports this same format.') }}</p>
                            <x-ui.textarea id="attendance-shift-template-export" wire:model="shiftTemplateExportJson" label="{{ __('Template JSON') }}" rows="10" class="mt-4" />
                        </x-ui.card>
                    @endif
                @endif

                @if ($section === 'shift-library')
                    @if ($shiftTemplateExportJson !== '')
                        <x-ui.card>
                            <h3 class="text-base font-semibold text-ink">{{ __('Template JSON ready') }}</h3>
                            <p class="mt-1 text-sm text-muted">{{ __('Copy this JSON into a shared template repository or country pack. Upload supports this same format.') }}</p>
                            <x-ui.textarea id="attendance-shift-library-template-export" wire:model="shiftTemplateExportJson" label="{{ __('Template JSON') }}" rows="10" class="mt-4" />
                        </x-ui.card>
                    @endif

                    <x-ui.card>
                        <div class="overflow-hidden rounded-2xl border border-border-default">
                            <table class="min-w-full divide-y divide-border-default text-sm">
                                <thead class="bg-surface-subtle/80">
                                    <tr>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Shift') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Schedule') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Status') }}</th>
                                        <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border-default bg-surface-card">
                                    @forelse ($shiftTemplates as $shift)
                                        <tr wire:key="shift-library-{{ $shift->id }}" class="transition hover:bg-surface-subtle/70">
                                            <td class="px-table-cell-x py-table-cell-y align-top">
                                                <button type="button" class="text-left font-medium text-accent hover:underline" wire:click="editShiftTemplate({{ $shift->id }})">{{ $shift->code }} - {{ $shift->name }}</button>
                                                <p class="mt-0.5 text-xs text-muted">{{ trans_choice(':count punch window|:count punch windows', $shift->punchWindows->count(), ['count' => $shift->punchWindows->count()]) }}</p>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y align-top font-mono text-xs text-muted">
                                                {{ $shift->starts_at }} → {{ $shift->ends_at }} · {{ trans_choice(':count minute|:count minutes', $shift->expected_work_minutes, ['count' => $shift->expected_work_minutes]) }}
                                                @if ($shift->crosses_midnight)
                                                    <div class="mt-1"><x-ui.badge variant="warning">{{ __('Cross-midnight') }}</x-ui.badge></div>
                                                @endif
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y align-top">
                                                <x-ui.badge :variant="$shift->status === 'active' ? 'success' : 'secondary'">{{ __(ucfirst($shift->status)) }}</x-ui.badge>
                                            </td>
                                            <td class="px-table-cell-x py-table-cell-y align-top">
                                                <div class="flex justify-end gap-2">
                                                    <x-ui.button type="button" size="sm" variant="secondary" wire:click="editShiftTemplate({{ $shift->id }})">{{ __('Edit') }}</x-ui.button>
                                                    <x-ui.button type="button" size="sm" variant="ghost" wire:click="duplicateShiftTemplate({{ $shift->id }})">{{ __('Duplicate') }}</x-ui.button>
                                                    <x-ui.button type="button" size="sm" variant="ghost" wire:click="exportShiftTemplate({{ $shift->id }})">{{ __('Download') }}</x-ui.button>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No shift templates configured. Start from a template above.') }}</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                @endif

                @if ($section === 'rosters')
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

                            @if ($employees->isEmpty() || $shiftTemplates->isEmpty() || $policyGroups->isEmpty())
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
                                    <x-ui.button type="submit" variant="primary" :disabled="! $canManage || $employees->isEmpty() || $shiftTemplates->isEmpty() || $policyGroups->isEmpty()">
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
                                                    <div class="mt-1 text-xs text-muted">{{ __('Type: :type', ['type' => __(str_replace('_', ' ', ucfirst($pattern->pattern_type)))]) }}</div>
                                                </div>
                                                <x-ui.badge :variant="$pattern->status === 'published' ? 'success' : 'warning'">{{ __(str_replace('_', ' ', ucfirst($pattern->status))) }}</x-ui.badge>
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
                                                    <x-ui.badge :variant="$assignment->publish_state === 'published' ? 'success' : 'warning'">{{ __(str_replace('_', ' ', ucfirst($assignment->publish_state))) }}</x-ui.badge>
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
                @endif

                @if ($section === 'allowances')
                    <div class="space-y-4">
                        <x-ui.card>
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="max-w-2xl">
                                    <h2 class="text-base font-semibold text-ink">{{ $editingAllowanceRuleId === null ? __('Create allowance rule') : __('Edit allowance rule') }}</h2>
                                    <p class="mt-1 text-sm text-muted">{{ __('Pick a recipe, fill only the fields that recipe needs, then validate the policy in Policy Studio before payroll handoff.') }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    @if ($editingAllowanceRuleId !== null)
                                        <x-ui.button type="button" variant="secondary" wire:click="cancelAllowanceEdit">{{ __('Cancel edit') }}</x-ui.button>
                                    @endif
                                    <x-ui.badge variant="info">{{ __('Deterministic setup') }}</x-ui.badge>
                                </div>
                            </div>

                            <form wire:submit="saveAllowanceRule" class="mt-4 space-y-4">
                                <div class="grid gap-3 md:grid-cols-5">
                                    <button type="button" wire:click="$set('allowanceConditionPreset', 'always')" class="rounded-2xl border p-3 text-left transition hover:-translate-y-0.5 {{ $allowanceConditionPreset === 'always' ? 'border-accent bg-surface-subtle' : 'border-border-default bg-surface-card' }}">
                                        <div class="text-sm font-medium text-ink">{{ __('Always') }}</div>
                                        <div class="mt-1 text-xs text-muted">{{ __('Pay when policy applies.') }}</div>
                                    </button>
                                    <button type="button" wire:click="$set('allowanceConditionPreset', 'min_worked')" class="rounded-2xl border p-3 text-left transition hover:-translate-y-0.5 {{ $allowanceConditionPreset === 'min_worked' ? 'border-accent bg-surface-subtle' : 'border-border-default bg-surface-card' }}">
                                        <div class="text-sm font-medium text-ink">{{ __('Worked time') }}</div>
                                        <div class="mt-1 text-xs text-muted">{{ __('Pay after minutes.') }}</div>
                                    </button>
                                    <button type="button" wire:click="$set('allowanceConditionPreset', 'clock_out_after')" class="rounded-2xl border p-3 text-left transition hover:-translate-y-0.5 {{ $allowanceConditionPreset === 'clock_out_after' ? 'border-accent bg-surface-subtle' : 'border-border-default bg-surface-card' }}">
                                        <div class="text-sm font-medium text-ink">{{ __('Late out') }}</div>
                                        <div class="mt-1 text-xs text-muted">{{ __('Pay after a time.') }}</div>
                                    </button>
                                    <button type="button" wire:click="$set('allowanceConditionPreset', 'clock_out_window')" class="rounded-2xl border p-3 text-left transition hover:-translate-y-0.5 {{ $allowanceConditionPreset === 'clock_out_window' ? 'border-accent bg-surface-subtle' : 'border-border-default bg-surface-card' }}">
                                        <div class="text-sm font-medium text-ink">{{ __('Time window') }}</div>
                                        <div class="mt-1 text-xs text-muted">{{ __('Pay inside window.') }}</div>
                                    </button>
                                    <button type="button" wire:click="$set('allowanceConditionPreset', 'min_worked_and_after')" class="rounded-2xl border p-3 text-left transition hover:-translate-y-0.5 {{ $allowanceConditionPreset === 'min_worked_and_after' ? 'border-accent bg-surface-subtle' : 'border-border-default bg-surface-card' }}">
                                        <div class="text-sm font-medium text-ink">{{ __('Worked + late') }}</div>
                                        <div class="mt-1 text-xs text-muted">{{ __('Require both.') }}</div>
                                    </button>
                                </div>

                                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.45fr)]">
                                    <div class="space-y-4 rounded-2xl border border-border-default p-card-inner">
                                        <div class="grid gap-4 md:grid-cols-2">
                                            <x-ui.input id="attendance-allowance-code" wire:model="allowanceCode" label="{{ __('Code') }}" placeholder="{{ __('NIGHT_ALLOWANCE') }}" required :error="$errors->first('allowanceCode')" />
                                            <x-ui.input id="attendance-allowance-name" wire:model="allowanceName" label="{{ __('Name') }}" placeholder="{{ __('Night allowance') }}" required :error="$errors->first('allowanceName')" />
                                        </div>

                                        <div class="grid gap-4 md:grid-cols-3">
                                            <x-ui.input id="attendance-allowance-amount" type="number" step="0.01" min="0.01" wire:model="allowanceAmount" label="{{ __('Amount') }}" required :error="$errors->first('allowanceAmount')" />
                                            <x-ui.input id="attendance-allowance-pay-item" wire:model="allowancePayItemCode" label="{{ __('Payroll pay item') }}" placeholder="{{ __('night_allowance') }}" :error="$errors->first('allowancePayItemCode')" />
                                            <x-ui.input id="attendance-allowance-effective-from" type="date" wire:model="allowanceEffectiveFrom" label="{{ __('Effective from') }}" required :error="$errors->first('allowanceEffectiveFrom')" />
                                        </div>

                                        @if (in_array($allowanceConditionPreset, ['min_worked', 'min_worked_and_after'], true))
                                        <x-ui.input id="attendance-allowance-min-worked" type="number" min="0" max="1440" wire:model="allowanceMinWorkedMinutes" label="{{ __('Minimum worked minutes') }}" :error="$errors->first('allowanceMinWorkedMinutes')" />
                                        @endif
                                        @if (in_array($allowanceConditionPreset, ['clock_out_after', 'clock_out_window', 'min_worked_and_after'], true))
                                        <x-ui.input id="attendance-allowance-clock-out-after" type="time" wire:model="allowanceClockOutAfter" label="{{ __('Clock-out after') }}" :error="$errors->first('allowanceClockOutAfter')" />
                                        @endif
                                        @if ($allowanceConditionPreset === 'clock_out_window')
                                        <x-ui.input id="attendance-allowance-clock-out-before" type="time" wire:model="allowanceClockOutBefore" label="{{ __('Clock-out before') }}" :error="$errors->first('allowanceClockOutBefore')" />
                                        @endif
                                    </div>

                                    <div class="space-y-4 rounded-2xl border border-border-default p-card-inner">
                                        <x-ui.select id="attendance-allowance-policy" wire:model="allowancePolicyGroupId" label="{{ __('Policy group') }}" :error="$errors->first('allowancePolicyGroupId')">
                                            <option value="">{{ __('Available to any policy') }}</option>
                                            @foreach ($policyGroups as $group)
                                                <option value="{{ $group->id }}">{{ $group->code }} - {{ $group->name }}</option>
                                            @endforeach
                                        </x-ui.select>
                                        <p class="text-xs text-muted">
                                            {{ __('Cannot find the policy you need?') }}
                                            <a href="{{ route('people.attendance.policy-studio.library') }}" class="text-accent hover:underline">{{ __('Open Policy Studio') }}</a>
                                        </p>
                                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                                            <x-ui.select id="attendance-allowance-type" wire:model="allowanceType" label="{{ __('Type') }}" :error="$errors->first('allowanceType')">
                                                <option value="daily">{{ __('Daily') }}</option>
                                                <option value="monthly">{{ __('Monthly') }}</option>
                                            </x-ui.select>
                                            <x-ui.select id="attendance-allowance-status" wire:model="allowanceStatus" label="{{ __('Status') }}" :error="$errors->first('allowanceStatus')">
                                                <option value="active">{{ __('Active') }}</option>
                                                <option value="inactive">{{ __('Inactive') }}</option>
                                            </x-ui.select>
                                        </div>
                                        <x-ui.select id="attendance-allowance-resolution" wire:model="allowanceResolutionMethod" label="{{ __('If more than one row matches') }}" :error="$errors->first('allowanceResolutionMethod')">
                                            <option value="sum">{{ __('Sum') }}</option>
                                            <option value="min">{{ __('Minimum') }}</option>
                                            <option value="max">{{ __('Maximum') }}</option>
                                        </x-ui.select>
                                    </div>
                                </div>

                                <div class="flex justify-end">
                                    <x-ui.button type="submit" variant="primary" :disabled="! $canManage">
                                        <x-icon name="heroicon-o-check-circle" class="h-4 w-4" />
                                        {{ $editingAllowanceRuleId === null ? __('Create rule') : __('Save changes') }}
                                    </x-ui.button>
                                </div>
                            </form>
                        </x-ui.card>

                        <x-ui.card>
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-base font-semibold text-ink">{{ __('Configured allowance rules') }}</h3>
                                <span class="text-xs text-muted">{{ trans_choice(':count rule|:count rules', $allowanceRules->count(), ['count' => $allowanceRules->count()]) }}</span>
                            </div>
                            <div class="mt-4 space-y-3">
                                @forelse ($allowanceRules as $rule)
                                    <div class="rounded-2xl border border-border-default p-3" wire:key="allowance-rule-{{ $rule->id }}">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <div class="font-medium text-ink">{{ $rule->code }} - {{ $rule->name }}</div>
                                                <div class="mt-1 text-xs text-muted">{{ __('Policy: :policy / Pay item: :code', ['policy' => $rule->policyGroup?->code ?? __('Any'), 'code' => $rule->payroll_pay_item_code ?? '-']) }}</div>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <x-ui.badge :variant="$rule->status === 'active' ? 'success' : 'warning'">{{ __(ucfirst($rule->status)) }}</x-ui.badge>
                                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="editAllowanceRule({{ $rule->id }})">{{ __('Edit') }}</x-ui.button>
                                                <x-ui.button type="button" size="sm" variant="danger" wire:click="deleteAllowanceRule({{ $rule->id }})" wire:confirm="{{ __('Delete this allowance rule?') }}">{{ __('Delete') }}</x-ui.button>
                                            </div>
                                        </div>
                                        <div class="mt-3 grid gap-2 text-xs text-muted sm:grid-cols-3">
                                            <div>{{ __('Type: :type', ['type' => __(ucfirst($rule->allowance_type))]) }}</div>
                                            <div>{{ __('Amount: :amount', ['amount' => $rule->condition_rows[0]['amount'] ?? '-']) }}</div>
                                            <div>{{ __('Effective: :date', ['date' => $rule->effective_from?->format('Y-m-d') ?? '-']) }}</div>
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-sm text-muted">{{ __('No allowance rules configured yet. Create the first rule on the left, then validate the policy in Policy Studio.') }}</p>
                                @endforelse
                            </div>
                        </x-ui.card>
                    </div>
                @endif

                @if ($section === 'locations')
                    <x-ui.card>
                        <h2 class="text-base font-semibold text-ink">{{ __('Locations') }}</h2>
                        <div class="mt-4 grid gap-4 md:grid-cols-2">
                            <div class="rounded-2xl border border-border-default p-4">
                                <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Geofences') }}</div>
                                <div class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ $geofences->count() }}</div>
                            </div>
                            <div class="rounded-2xl border border-border-default p-4">
                                <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Geofence Groups') }}</div>
                                <div class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ $geofenceGroups->count() }}</div>
                            </div>
                        </div>
                    </x-ui.card>
                @endif
        @endif

        <x-ui.modal wire:model="showPolicyTemplateImportModal" class="max-w-2xl">
            <div class="p-6 space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-ink">{{ __('Upload Template') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Choose a JSON file containing one policy template object, or an array of template objects.') }}</p>
                </div>
                <div>
                    <label for="attendance-policy-template-upload" class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Template JSON file') }}</label>
                    <input id="attendance-policy-template-upload" type="file" accept="application/json,.json" wire:model="policyTemplateUpload" class="mt-1 block w-full text-sm text-ink file:mr-4 file:rounded file:border-0 file:bg-surface-subtle file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-ink hover:file:bg-surface-subtle/80" />
                    @error('policyTemplateUpload') <p class="mt-1 text-sm text-status-danger">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showPolicyTemplateImportModal', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="button" variant="primary" wire:click="importPolicyTemplate">{{ __('Upload into builder') }}</x-ui.button>
                </div>
            </div>
        </x-ui.modal>

        <x-ui.modal wire:model="showShiftTemplateImportModal" class="max-w-2xl">
            <div class="p-6 space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-ink">{{ __('Upload Shift Template') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Choose a JSON file containing one shift template object, or an array of template objects.') }}</p>
                </div>
                <div>
                    <label for="attendance-shift-template-upload" class="text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Template JSON file') }}</label>
                    <input id="attendance-shift-template-upload" type="file" accept="application/json,.json" wire:model="shiftTemplateUpload" class="mt-1 block w-full text-sm text-ink file:mr-4 file:rounded file:border-0 file:bg-surface-subtle file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-ink hover:file:bg-surface-subtle/80" />
                    @error('shiftTemplateUpload') <p class="mt-1 text-sm text-status-danger">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showShiftTemplateImportModal', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="button" variant="primary" wire:click="importShiftTemplate">{{ __('Upload into builder') }}</x-ui.button>
                </div>
            </div>
        </x-ui.modal>

        <x-ui.modal wire:model="showOvertimeModal" class="max-w-2xl">
            <form wire:submit="submitOvertimeRequest" class="p-6 space-y-4">
                <div>
                    <h2 class="text-lg font-semibold text-ink">{{ __('Request Overtime') }}</h2>
                    <p class="mt-1 text-sm text-muted">{{ __('Submitted overtime stays out of payroll until an approver approves and queues it.') }}</p>
                </div>
                <div class="grid gap-4 md:grid-cols-3">
                    <x-ui.input id="attendance-ot-date" type="date" wire:model="overtimeDate" label="{{ __('Date') }}" required :error="$errors->first('overtimeDate')" />
                    <x-ui.input id="attendance-ot-start" type="time" wire:model="overtimeStartsAt" label="{{ __('Start') }}" required :error="$errors->first('overtimeStartsAt')" />
                    <x-ui.input id="attendance-ot-end" type="time" wire:model="overtimeEndsAt" label="{{ __('End') }}" required :error="$errors->first('overtimeEndsAt')" />
                </div>
                <x-ui.input id="attendance-ot-hours" type="number" step="0.25" min="0.25" max="24" wire:model="overtimeRequestedHours" label="{{ __('Requested Hours') }}" required :error="$errors->first('overtimeRequestedHours')" />
                <x-ui.textarea id="attendance-ot-reason" wire:model="overtimeReason" label="{{ __('Reason') }}" rows="3" :error="$errors->first('overtimeReason')" />
                <div class="flex justify-end gap-2">
                    <x-ui.button type="button" variant="secondary" wire:click="$set('showOvertimeModal', false)">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button type="submit" variant="primary">{{ __('Submit Request') }}</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    </div>
</div>
