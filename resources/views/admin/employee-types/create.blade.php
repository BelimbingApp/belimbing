<x-layouts.app :title="__('Add Employee Type')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Add Employee Type')" :subtitle="__('Create a custom employee type')">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('admin.employee-types.index') }}">
                    <x-icon name="heroicon-o-arrow-left" class="h-5 w-5" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form method="POST" action="{{ route('admin.employee-types.store') }}" class="max-w-lg space-y-4">
                @csrf
                <x-ui.input name="code" label="{{ __('Code') }}" required value="{{ old('code') }}" placeholder="{{ __('e.g. consultant') }}" :error="$errors->first('code')" />
                <p class="-mt-2 text-xs text-muted">{{ __('Lowercase letters, numbers, and underscores. Must start with a letter.') }}</p>

                <x-ui.input name="label" label="{{ __('Label') }}" required value="{{ old('label') }}" placeholder="{{ __('e.g. Consultant') }}" :error="$errors->first('label')" />

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary">{{ __('Create') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
