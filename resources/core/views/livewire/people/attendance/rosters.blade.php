<?php

use App\Modules\People\Attendance\Livewire\Rosters;

/** @var Rosters $this */
?>

<div>
    <x-slot name="title">{{ __('Roster') }}</x-slot>

    <div class="space-y-section-gap">
        @if ($isMySchedule)
            <x-ui.page-header
                :title="__('My Schedule')"
                :subtitle="__('Your upcoming shifts. Acknowledge each week once you\'ve reviewed it.')">
            </x-ui.page-header>
        @else
            <x-ui.page-header
                :title="__('Roster')"
                :subtitle="$mode === 'list'
                    ? __('Roster assignments pair employees with a shift and policy group over a date range. Attendance days resolve against the assignment that covers their date.')
                    : __('Filter the workforce, pick a shift and policy, then save the assignment as a draft or published roster.')">
                <x-slot name="actions">
                    @if ($mode === 'list')
                        <x-ui.button type="button" variant="primary" wire:click="startNewRosterAssignment">
                            <x-icon name="heroicon-o-plus-circle" class="h-4 w-4" />
                            {{ __('New roster assignment') }}
                        </x-ui.button>
                    @else
                        <x-ui.button type="button" variant="secondary" wire:click="cancelRosterForm">
                            <x-icon name="heroicon-o-arrow-left" class="h-4 w-4" />
                            {{ __('Back') }}
                        </x-ui.button>
                    @endif
                </x-slot>
                <x-slot name="help">
                    @if ($mode === 'list')
                        {{ __('Each row is one employee-and-period pairing. Delete to remove the assignment; create a new one to extend or replace it. Overlapping ranges per employee are blocked at save time.') }}
                    @else
                        {{ __('Shift answers what time they work; pattern answers how that repeats. Published rosters drive attendance resolution; drafts stay safe to prepare.') }}
                    @endif
                </x-slot>
            </x-ui.page-header>
        @endif

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

        @if ($mode === 'list')
            @include('livewire.people.attendance.partials.rosters-list')
        @else
            @include('livewire.people.attendance.partials.rosters-form')
        @endif
    </div>
</div>
