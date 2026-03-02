<x-layouts.app :title="__('Create User')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Create User')" :subtitle="__('Add a new user to the system')">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('admin.users.index') }}">
                    <x-icon name="heroicon-o-arrow-left" class="h-5 w-5" />
                    {{ __('Back') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-6">
                @csrf

                <x-ui.select name="company_id" label="{{ __('Company') }}" :error="$errors->first('company_id')">
                    <option value="">{{ __('No company') }}</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}" @selected(old('company_id') == $company->id)>{{ $company->name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.input
                    name="name"
                    :value="old('name')"
                    label="{{ __('Name') }}"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    placeholder="{{ __('Enter user name') }}"
                    :error="$errors->first('name')"
                />

                <x-ui.input
                    name="email"
                    :value="old('email')"
                    label="{{ __('Email') }}"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="{{ __('Enter email address') }}"
                    :error="$errors->first('email')"
                />

                <x-ui.input
                    name="password"
                    label="{{ __('Password') }}"
                    type="password"
                    required
                    autocomplete="new-password"
                    placeholder="{{ __('Enter password') }}"
                    :error="$errors->first('password')"
                />

                <x-ui.input
                    name="password_confirmation"
                    label="{{ __('Confirm Password') }}"
                    type="password"
                    required
                    autocomplete="new-password"
                    placeholder="{{ __('Confirm password') }}"
                    :error="$errors->first('password_confirmation')"
                />

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Create User') }}
                    </x-ui.button>
                    <x-ui.button variant="ghost" as="a" href="{{ route('admin.users.index') }}">
                        {{ __('Cancel') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
