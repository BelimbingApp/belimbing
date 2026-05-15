<?php

use App\Modules\People\Attendance\Livewire\PolicyStudio\Shifts\Library;

/** @var Library $this */
?>

<div>
    <x-slot name="title">{{ __('Shift Library') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Shift Library')" :subtitle="__('Manage reusable shift templates supervisors can select while building rosters.')">
            @if ($mode === 'list')
                <x-slot name="actions">
                    <x-ui.button type="button" variant="primary" wire:click="startNewShift">
                        <x-icon name="heroicon-o-plus-circle" class="h-4 w-4" />
                        {{ __('New shift') }}
                    </x-ui.button>
                </x-slot>
            @endif
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

        @if ($mode === 'list')
            @include('livewire.people.attendance.policy-studio.partials.shifts-library-table')
        @else
            @include('livewire.people.attendance.policy-studio.partials.shifts-form')
        @endif
    </div>
</div>
