<div>
    <x-slot name="title">{{ __('Add Employee Type') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Add Employee Type')" :subtitle="__('Create a custom employee type')">
            <x-slot name="actions">
                <x-ui.link href="{{ route('admin.employee-types.index') }}">
                    {{ __('Back') }}
                </x-ui.link>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form wire:submit="createType" class="space-y-4 max-w-lg">
                <x-ui.input
                    id="employee-type-code"
                    wire:model="code"
                    label="{{ __('Code') }}"
                    required
                    placeholder="{{ __('e.g. consultant') }}"
                    :error="$errors->first('code')"
                />
                <p class="text-xs text-muted -mt-2">{{ __('Lowercase letters, numbers, and underscores. Must start with a letter.') }}</p>

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
                        {{ __('Create') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
