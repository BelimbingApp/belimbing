<?php

use App\Modules\People\Attendance\Livewire\MyAttendance;

/** @var MyAttendance $this */
?>

<div>
    <x-slot name="title">{{ __('My Attendance') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('My Attendance')" :subtitle="__('Review your timecard and record web clock events where enabled.')">
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

        <div class="grid gap-4 md:grid-cols-2">
            <x-ui.card>
                <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('My Attendance Days') }}</div>
                <div class="mt-2 text-3xl font-semibold tabular-nums text-ink">{{ $attendanceDays->count() }}</div>
            </x-ui.card>
            <x-ui.card>
                <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('My Pending OT') }}</div>
                <div class="mt-2 text-3xl font-semibold tabular-nums text-ink">{{ $pendingOvertime->count() }}</div>
            </x-ui.card>
        </div>

        @include('livewire.people.attendance.partials.attendance-days-card')

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
