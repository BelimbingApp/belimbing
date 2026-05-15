<?php

use App\Modules\People\Attendance\Livewire\Approvals;

/** @var Approvals $this */
?>

<div>
    <x-slot name="title">{{ __('Attendance Approvals') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Attendance Approvals')" :subtitle="__('Review overtime and attendance exceptions before they affect payroll.')">
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

        @include('livewire.people.attendance.partials.approvals')
    </div>
</div>
