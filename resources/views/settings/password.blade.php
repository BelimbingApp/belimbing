<x-layouts.app :title="__('Password Settings')">
    <section class="w-full">
        @include('partials.settings-heading')

        <x-settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
            <form method="POST" action="{{ route('password.update.settings') }}" class="mt-6 space-y-6">
                @csrf

                <x-ui.input
                    name="current_password"
                    label="{{ __('Current password') }}"
                    type="password"
                    required
                    autocomplete="current-password"
                    :error="$errors->first('current_password')"
                />
                <x-ui.input
                    name="password"
                    label="{{ __('New password') }}"
                    type="password"
                    required
                    autocomplete="new-password"
                    :error="$errors->first('password')"
                />
                <x-ui.input
                    name="password_confirmation"
                    label="{{ __('Confirm Password') }}"
                    type="password"
                    required
                    autocomplete="new-password"
                    :error="$errors->first('password_confirmation')"
                />

                <div class="flex items-center gap-4">
                    <div class="flex items-center justify-end">
                        <x-ui.button type="submit" variant="primary" class="w-full" data-test="update-password-button">
                            {{ __('Save') }}
                        </x-ui.button>
                    </div>

                    @if (session('status') === 'password-updated')
                        <p class="me-3 text-sm text-muted">{{ __('Saved.') }}</p>
                    @endif
                </div>
            </form>
        </x-settings.layout>
    </section>
</x-layouts.app>
