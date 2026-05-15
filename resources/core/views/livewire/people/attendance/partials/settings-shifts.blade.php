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
    @include('livewire.people.attendance.partials.template-json-export', [
        'id' => 'attendance-shift-template-export',
        'field' => 'shiftTemplateExportJson',
    ])
@endif
