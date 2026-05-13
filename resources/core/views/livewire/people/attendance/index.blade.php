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
                        <x-ui.button type="button" variant="primary" wire:click="clock('in')" :disabled="$currentEmployeeId === null">
                            <x-icon name="heroicon-o-arrow-right-on-rectangle" class="h-4 w-4" />
                            {{ __('Clock In') }}
                        </x-ui.button>
                        <x-ui.button type="button" variant="secondary" wire:click="clock('out')" :disabled="$currentEmployeeId === null">
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
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No attendance days found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        @if ($surface === 'approvals')
            <x-ui.card>
                <h2 class="text-base font-semibold text-ink">{{ __('Submitted Overtime') }}</h2>
                <div class="mt-4 overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Employee') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Window') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Requested Hours') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Reason') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-default bg-surface-card">
                            @forelse ($pendingOvertime as $request)
                                <tr wire:key="attendance-ot-{{ $request->id }}">
                                    <td class="px-table-cell-x py-table-cell-y">{{ $request->employee?->full_name ?? __('Employee #:id', ['id' => $request->employee_id]) }}</td>
                                    <td class="px-table-cell-x py-table-cell-y font-mono text-xs">{{ $request->starts_at?->format('Y-m-d H:i') }} - {{ $request->ends_at?->format('H:i') }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-right tabular-nums">{{ number_format($request->requested_minutes / 60, 2) }}</td>
                                    <td class="px-table-cell-x py-table-cell-y">{{ $request->reason }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No overtime requests are waiting for approval.') }}</td>
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
    </div>
</div>
