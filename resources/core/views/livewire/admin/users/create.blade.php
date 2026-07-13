<div>
    <x-slot name="title">{{ __('Create User') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Create User')" :subtitle="__('Add a new user to the system')">
            <x-slot name="actions">
                <x-ui.link href="{{ route('admin.users.index') }}">
                    {{ __('Back') }}
                </x-ui.link>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form wire:submit="store" class="space-y-6">
                <x-ui.select
                    id="user-company"
                    wire:model.live="companyId"
                    label="{{ __('Company') }}"
                    :error="$errors->first('companyId')"
                >
                    <option value="">{{ __('No company') }}</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.input
                    id="user-name"
                    wire:model="name"
                    label="{{ __('Name') }}"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    placeholder="{{ __('Enter user name') }}"
                    :error="$errors->first('name')"
                />

                <x-ui.input
                    id="user-email"
                    wire:model="email"
                    label="{{ __('Email') }}"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="{{ __('Enter email address') }}"
                    :error="$errors->first('email')"
                />

                <x-ui.input
                    id="user-password"
                    wire:model="password"
                    label="{{ __('Password') }}"
                    type="password"
                    required
                    autocomplete="new-password"
                    placeholder="{{ __('Enter password') }}"
                    :error="$errors->first('password')"
                />

                <x-ui.input
                    id="user-password-confirmation"
                    wire:model="passwordConfirmation"
                    label="{{ __('Confirm Password') }}"
                    type="password"
                    required
                    autocomplete="new-password"
                    placeholder="{{ __('Confirm password') }}"
                    :error="$errors->first('passwordConfirmation')"
                />

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Create User') }}
                    </x-ui.button>
                    <x-ui.button variant="ghost" as="a" href="{{ route('admin.users.index') }}" wire:navigate>
                        {{ __('Cancel') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
