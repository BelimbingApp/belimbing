<?php

use App\Modules\People\Attendance\Livewire\Rosters;

/** @var Rosters $this */
?>

<div>
    <x-slot name="title">{{ __('Roster Builder') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Roster Builder')" :subtitle="__('Assign employees to shifts and policy groups so supervisors can publish clean rosters.')">
            <x-slot name="help">
                {{ __('Each assignment pairs an employee with a shift template and policy group over a date range. Attendance days resolve against the assignment that covers their date. Overlapping ranges per employee are blocked.') }}
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

        @include('livewire.people.attendance.partials.rosters-form')
    </div>
</div>
