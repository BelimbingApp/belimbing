<div>
    <x-slot name="title">{{ __('Edit Employee Type') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Edit Employee Type')" :subtitle="$employeeType->code">
            <x-slot name="actions">
                <x-ui.link href="{{ route('admin.employee-types.index') }}">
                    {{ __('Back') }}
                </x-ui.link>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form wire:submit="save" class="space-y-4 max-w-lg">
                <div>
                    <p class="block text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Code') }}</p>
                    <p class="text-sm text-ink font-mono mt-0.5">{{ $employeeType->code }}</p>
                    <p class="text-xs text-muted mt-1">{{ __('Code cannot be changed.') }}</p>
                </div>

                <x-ui.input
                    id="employee-type-label"
                    wire:model="label"
                    label="{{ __('Label') }}"
                    required
                    placeholder="{{ __('e.g. Consultant') }}"
                    :error="$errors->first('label')"
                />

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Save') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
