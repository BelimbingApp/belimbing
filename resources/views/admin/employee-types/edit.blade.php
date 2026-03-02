<x-layouts.app :title="__('Edit Employee Type')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Edit Employee Type')" :subtitle="$employeeType->code">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('admin.employee-types.index') }}">
                    <x-icon name="heroicon-o-arrow-left" class="h-5 w-5" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form method="POST" action="{{ route('admin.employee-types.update', $employeeType) }}" class="max-w-lg space-y-4">
                @csrf
                @method('PATCH')

                <div>
                    <label class="block text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Code') }}</label>
                    <p class="mt-0.5 font-mono text-sm text-ink">{{ $employeeType->code }}</p>
                    <p class="mt-1 text-xs text-muted">{{ __('Code cannot be changed.') }}</p>
                </div>

                <x-ui.input name="label" label="{{ __('Label') }}" required value="{{ old('label', $employeeType->label) }}" placeholder="{{ __('e.g. Consultant') }}" :error="$errors->first('label')" />

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary">{{ __('Save') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
