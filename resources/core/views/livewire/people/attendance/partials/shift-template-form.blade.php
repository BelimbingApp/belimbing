<div class="flex items-center justify-between gap-3">
    <button type="button" wire:click="cancelShiftEdit" class="inline-flex items-center gap-1 text-sm font-medium text-muted transition hover:text-accent">
        <x-icon name="heroicon-o-arrow-left" class="h-4 w-4" />
        {{ __('Back to shifts') }}
    </button>
    <p class="text-sm font-medium text-ink">
        {{ $editingShiftTemplateId === null ? __('New shift') : __('Editing :code', ['code' => $shiftCode ?: '—']) }}
    </p>
</div>

{{-- Templates are a creation affordance only — hide once a saved shift is loaded for edit or duplicate. --}}
@if ($selectedShiftTemplateKey !== 'saved-shift')
    <x-ui.template-picker
        :templates="$shiftTemplatePresets"
        :selected-key="$selectedShiftTemplateKey"
        :show-all="$showAllShiftTemplates"
        select-action="useShiftTemplate"
        upload-action="$set('showShiftTemplateImportModal', true)"
    />
@endif

@if ($showShiftBuilderForm)
    <form wire:submit="saveShiftTemplate" class="space-y-4">
        @if ($errors->any())
            <x-ui.alert variant="danger">
                <p class="font-medium">{{ __('Fix these before saving:') }}</p>
                <ul class="mt-2 list-disc pl-5 text-sm">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        @endif

        <x-ui.card>
            <div>
                <h2 class="text-base font-semibold text-ink">{{ __('Identification') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('How this shift appears in rosters, policy validation and audit logs.') }}</p>
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
                    <a href="{{ route('people.attendance.policy-groups') }}" target="_blank" rel="noopener noreferrer" class="font-medium text-accent hover:underline">{{ __('Open Policy Groups in a new tab') }}</a>
                </x-ui.alert>
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-ui.input id="attendance-shift-starts-at" type="time" wire:model="shiftStartsAt" label="{{ __('Shift start') }}" required help="{{ __('When scheduled work begins.') }}" :error="$errors->first('shiftStartsAt')" />
                    <x-ui.input id="attendance-shift-ends-at" type="time" wire:model="shiftEndsAt" label="{{ __('Shift end') }}" required help="{{ __('Use an earlier end time for overnight shifts.') }}" :error="$errors->first('shiftEndsAt')" />
                    <x-ui.input id="attendance-shift-expected-work-minutes" type="number" min="1" max="1440" wire:model="shiftExpectedWorkMinutes" label="{{ __('Expected work') }}" suffix="{{ __('min') }}" required help="{{ __('Payable work time before policy rounding or exceptions.') }}" :error="$errors->first('shiftExpectedWorkMinutes')" />
                </div>
                <div class="space-y-3">
                    <div class="flex justify-end">
                        @if (count($shiftBreaks) < 2)
                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="addShiftBreak">
                                <x-icon name="heroicon-o-plus-circle" class="h-4 w-4" />
                                {{ count($shiftBreaks) === 0 ? __('Add Break') : __('Add Another Break') }}
                            </x-ui.button>
                        @endif
                    </div>
                    @if (count($shiftBreaks) > 0)
                        @foreach ($shiftBreaks as $index => $break)
                            <div class="grid gap-4 sm:grid-cols-4" wire:key="shift-break-{{ $index }}">
                                <x-ui.input id="attendance-shift-break-{{ $index }}-label" wire:model="shiftBreaks.{{ $index }}.label" label="{{ __('Label') }}" placeholder="{{ __('Lunch') }}" :error="$errors->first('shiftBreaks.'.$index.'.label')" />
                                <x-ui.input id="attendance-shift-break-{{ $index }}-starts-at" type="time" wire:model="shiftBreaks.{{ $index }}.starts_at" label="{{ __('Start') }}" :error="$errors->first('shiftBreaks.'.$index.'.starts_at')" />
                                <x-ui.input id="attendance-shift-break-{{ $index }}-ends-at" type="time" wire:model="shiftBreaks.{{ $index }}.ends_at" label="{{ __('End') }}" :error="$errors->first('shiftBreaks.'.$index.'.ends_at')" />
                                <div class="space-y-1">
                                    <span class="block text-[11px] uppercase tracking-wider font-semibold text-transparent" aria-hidden="true">{{ __('Paid') }}</span>
                                    <div class="pt-[14px]">
                                        <x-ui.checkbox id="attendance-shift-break-{{ $index }}-paid" wire:model="shiftBreaks.{{ $index }}.paid" label="{{ __('Paid') }}" help="{{ __('Paid breaks count as worked time; unpaid breaks are deducted by payroll when the evaluator runs.') }}" />
                                    </div>
                                </div>
                                <div class="sm:col-span-4 flex justify-end">
                                    <x-ui.button type="button" size="sm" variant="danger" wire:click="removeShiftBreak({{ $index }})">{{ __('Remove') }}</x-ui.button>
                                </div>
                            </div>
                        @endforeach
                    @endif
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
                <x-ui.select id="attendance-shift-payroll-attribution" wire:model="shiftPayrollAttribution" label="{{ __('Payroll date') }}" required help="{{ __('Which date receives attendance and payroll attribution for overnight shifts.') }}" :error="$errors->first('shiftPayrollAttribution')">
                    <option value="shift_start_date">{{ __('Shift start date') }}</option>
                    <option value="shift_end_date">{{ __('Shift end date') }}</option>
                </x-ui.select>
                <x-ui.select id="attendance-shift-status" wire:model="shiftStatus" label="{{ __('Status') }}" required help="{{ __('Active shifts can be used in rosters.') }}" :error="$errors->first('shiftStatus')">
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                </x-ui.select>
            </div>
        </x-ui.card>

        <div class="flex flex-wrap justify-end gap-2">
            <x-ui.button type="button" variant="secondary" wire:click="cancelShiftEdit">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button as="a" variant="secondary" href="{{ route('people.attendance.policy-groups.validator') }}">
                {{ __('Open Validator') }}
            </x-ui.button>
            <x-ui.button type="submit" variant="primary" :disabled="! $canManage">
                {{ $editingShiftTemplateId === null ? __('Create shift') : __('Save shift') }}
            </x-ui.button>
        </div>
    </form>
@endif

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
