<?php

use App\Modules\People\Attendance\Livewire\Shifts\Builder;

/** @var Builder $this */
?>

<div>
    <x-slot name="title">{{ __('Shift Builder') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Shift Builder')" :subtitle="__('Maintain reusable shift times, cross-midnight settings, punch windows, breaks, and expected work minutes.')">
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

        @include('livewire.people.attendance.partials.settings-shifts')

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
    </div>
</div>
