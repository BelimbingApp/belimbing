<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <x-ui.session-flash />

        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <x-ui.input id="profile-name" wire:model="name" label="{{ __('Name') }}" type="text" required autofocus autocomplete="name" :error="$errors->first('name')" />

            <div>
                <x-ui.input id="profile-email" wire:model="email" label="{{ __('Email') }}" type="email" required autocomplete="email" :error="$errors->first('email')" />

                @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail &&! auth()->user()->hasVerifiedEmail())
                    <div>
                        <p class="mt-4">
                            {{ __('Your email address is unverified.') }}

                            <button wire:click.prevent="resendVerificationNotification" class="text-sm text-primary hover:underline cursor-pointer">
                                {{ __('Click here to re-send the verification email.') }}
                            </button>
                        </p>

                        @if (session('status') === 'verification-link-sent')
                            <p class="mt-2 font-medium text-green-600 dark:text-green-400">
                                {{ __('A new verification link has been sent to your email address.') }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>

            <x-ui.select
                id="profile-landing"
                wire:model="landingMenuId"
                :label="__('Landing page')"
                :help="__('The first page you see after logging in.')"
                :error="$errors->first('landingMenuId')"
            >
                <option value="">{{ __('Default') }}</option>
                @foreach ($landingOptions as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <x-ui.button type="submit" variant="primary" class="w-full" data-test="update-profile-button">
                        {{ __('Save') }}
                    </x-ui.button>
                </div>
            </div>
        </form>

        <livewire:profile.delete-user-form />
    </x-settings.layout>
</section>
