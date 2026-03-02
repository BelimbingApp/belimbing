<x-layouts.app :title="__('Profile Settings')">
    <section class="w-full">
        @include('partials.settings-heading')

        <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
            <form method="POST" action="{{ route('profile.update') }}" class="my-6 w-full space-y-6">
                @csrf

                <x-ui.input
                    name="name"
                    :value="old('name', auth()->user()->name)"
                    label="{{ __('Name') }}"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    :error="$errors->first('name')"
                />

                <div>
                    <x-ui.input
                        name="email"
                        :value="old('email', auth()->user()->email)"
                        label="{{ __('Email') }}"
                        type="email"
                        required
                        autocomplete="email"
                        :error="$errors->first('email')"
                    />
                </div>

                <div class="flex items-center gap-4">
                    <div class="flex items-center justify-end">
                        <x-ui.button type="submit" variant="primary" class="w-full" data-test="update-profile-button">
                            {{ __('Save') }}
                        </x-ui.button>
                    </div>

                    @if (session('status') === 'profile-updated')
                        <p class="me-3 text-sm text-muted">{{ __('Saved.') }}</p>
                    @endif
                </div>
            </form>

            @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
                <div>
                    <p class="mt-4">
                        {{ __('Your email address is unverified.') }}
                    </p>

                    <form method="POST" action="{{ route('profile.resend-verification') }}" class="mt-1">
                        @csrf
                        <button type="submit" class="cursor-pointer text-sm text-primary hover:underline">
                            {{ __('Click here to re-send the verification email.') }}
                        </button>
                    </form>

                    @if (session('status') === 'verification-link-sent')
                        <p class="mt-2 font-medium text-green-600 dark:text-green-400">
                            {{ __('A new verification link has been sent to your email address.') }}
                        </p>
                    @endif
                </div>
            @endif

            @include('settings.delete-account')
        </x-settings.layout>
    </section>
</x-layouts.app>
