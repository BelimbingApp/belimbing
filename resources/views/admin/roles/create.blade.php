<x-layouts.app :title="__('Create Role')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Create Role')" :subtitle="__('Create a new custom role')">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('admin.roles.index') }}">
                    <x-icon name="heroicon-o-arrow-left" class="h-5 w-5" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form method="POST" action="{{ route('admin.roles.store') }}" class="max-w-lg space-y-4">
                @csrf

                <x-ui.input
                    name="name"
                    :value="old('name')"
                    label="{{ __('Name') }}"
                    required
                    placeholder="{{ __('e.g. Sales Manager') }}"
                    :error="$errors->first('name')"
                />

                <x-ui.input
                    name="code"
                    :value="old('code')"
                    label="{{ __('Code') }}"
                    required
                    placeholder="{{ __('e.g. sales_manager') }}"
                    :error="$errors->first('code')"
                />
                <p class="-mt-2 text-xs text-muted">{{ __('Lowercase letters, numbers, and underscores only.') }}</p>

                <x-ui.textarea
                    name="description"
                    label="{{ __('Description') }}"
                    rows="3"
                    placeholder="{{ __('What this role is for...') }}"
                    :error="$errors->first('description')"
                >{{ old('description') }}</x-ui.textarea>

                <x-ui.select name="company_id" label="{{ __('Company Scope') }}" :error="$errors->first('company_id')">
                    <option value="">{{ __('Global (all companies)') }}</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}" @selected((string) old('company_id') === (string) $company->id)>{{ $company->name }}</option>
                    @endforeach
                </x-ui.select>
                <p class="-mt-2 text-xs text-muted">{{ __('Leave as global to make this role available to all companies, or scope it to a specific company.') }}</p>

                <div class="pt-2">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Create Role') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
