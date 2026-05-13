<?php
/** @var \App\Modules\People\Attendance\Livewire\Index $this */
?>

<div>
    <x-slot name="title">{{ $surfaceTitle }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="$surfaceTitle" :subtitle="$surfaceSubtitle">
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
            <div class="grid gap-4 xl:grid-cols-2">
                <x-ui.card>
                    <h2 class="text-base font-semibold text-ink">{{ __('Shift Templates') }}</h2>
                    <div class="mt-4 space-y-3">
                        @forelse ($shiftTemplates as $shift)
                            <div class="rounded-lg border border-border-default p-3" wire:key="shift-{{ $shift->id }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="font-medium text-ink">{{ $shift->code }} - {{ $shift->name }}</div>
                                        <div class="font-mono text-xs text-muted">{{ $shift->starts_at }} - {{ $shift->ends_at }} / {{ trans_choice(':count punch window|:count punch windows', $shift->punchWindows->count(), ['count' => $shift->punchWindows->count()]) }}</div>
                                    </div>
                                    @if ($shift->crosses_midnight)
                                        <x-ui.badge variant="warning">{{ __('Cross-midnight') }}</x-ui.badge>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-muted">{{ __('No shift templates configured.') }}</p>
                        @endforelse
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <h2 class="text-base font-semibold text-ink">{{ __('Policy Groups') }}</h2>
                    <div class="mt-4 space-y-3">
                        @forelse ($policyGroups as $group)
                            <div class="rounded-lg border border-border-default p-3" wire:key="policy-group-{{ $group->id }}">
                                <div class="font-medium text-ink">{{ $group->code }} - {{ $group->name }}</div>
                                <div class="mt-1 text-xs text-muted">{{ __('Version :version / :count allowance rules', ['version' => $group->version, 'count' => $group->allowanceRules->count()]) }}</div>
                            </div>
                        @empty
                            <p class="text-sm text-muted">{{ __('No policy groups configured.') }}</p>
                        @endforelse
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <h2 class="text-base font-semibold text-ink">{{ __('Allowance Rules') }}</h2>
                    <div class="mt-4 space-y-3">
                        @forelse ($allowanceRules as $rule)
                            <div class="rounded-lg border border-border-default p-3" wire:key="allowance-rule-{{ $rule->id }}">
                                <div class="font-medium text-ink">{{ $rule->code }} - {{ $rule->name }}</div>
                                <div class="mt-1 text-xs text-muted">{{ __('Type: :type / Pay item: :code / Ceiling: :ceiling', ['type' => $rule->allowance_type, 'code' => $rule->payroll_pay_item_code ?? '-', 'ceiling' => $rule->ceiling_amount ?? '-']) }}</div>
                            </div>
                        @empty
                            <p class="text-sm text-muted">{{ __('No allowance rules configured.') }}</p>
                        @endforelse
                    </div>
                </x-ui.card>

                <x-ui.card>
                    <h2 class="text-base font-semibold text-ink">{{ __('Roster and Geofence Setup') }}</h2>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Roster Patterns') }}</div>
                            <div class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ $rosterPatterns->count() }}</div>
                            <div class="mt-4 text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Roster Assignments') }}</div>
                            <div class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ $rosterAssignments->count() }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Geofences') }}</div>
                            <div class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ $geofences->count() }}</div>
                            <div class="mt-4 text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Geofence Groups') }}</div>
                            <div class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ $geofenceGroups->count() }}</div>
                        </div>
                    </div>
                </x-ui.card>
            </div>
        @endif

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
