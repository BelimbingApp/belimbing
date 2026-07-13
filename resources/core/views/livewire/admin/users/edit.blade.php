<div>
    <x-slot name="title">{{ __('Edit User') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Edit User')" :subtitle="__('Update user information')">
            <x-slot name="actions">
                <x-ui.link href="{{ route('admin.users.index') }}">
                    {{ __('Back') }}
                </x-ui.link>
            </x-slot>
        </x-ui.page-header>

        <x-ui.card>
            <form wire:submit="update" class="space-y-6">
                <x-ui.select
                    id="user-edit-company"
                    wire:model="companyId"
                    label="{{ __('Company') }}"
                    :error="$errors->first('companyId')"
                >
                    <option value="">{{ __('No company') }}</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company->id }}">{{ $company->name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.input
                    id="user-edit-name"
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
                    id="user-edit-email"
                    wire:model="email"
                    label="{{ __('Email') }}"
                    type="email"
                    required
                    autocomplete="email"
                    placeholder="{{ __('Enter email address') }}"
                    :error="$errors->first('email')"
                />

                <div class="border-t border-border-input my-6 pt-6">
                    <h3 class="text-sm font-medium text-ink mb-4">{{ __('Change Password (Optional)') }}</h3>
                </div>

                <x-ui.input
                    id="user-edit-password"
                    wire:model="password"
                    label="{{ __('New Password') }}"
                    type="password"
                    autocomplete="new-password"
                    placeholder="{{ __('Leave blank to keep current password') }}"
                    :error="$errors->first('password')"
                />

                <x-ui.input
                    id="user-edit-password-confirmation"
                    wire:model="passwordConfirmation"
                    label="{{ __('Confirm New Password') }}"
                    type="password"
                    autocomplete="new-password"
                    placeholder="{{ __('Confirm new password') }}"
                    :error="$errors->first('passwordConfirmation')"
                />

                <div class="flex items-center gap-4">
                    <x-ui.button type="submit" variant="primary">
                        {{ __('Update User') }}
                    </x-ui.button>
                    <x-ui.button variant="ghost" as="a" href="{{ route('admin.users.index') }}" wire:navigate>
                        {{ __('Cancel') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
