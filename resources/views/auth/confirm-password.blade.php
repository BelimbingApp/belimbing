<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirm password')"
            :description="__('This is a secure area of the application. Please confirm your password before continuing.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.confirm') }}" class="flex flex-col gap-6">
            @csrf

            <x-ui.input
                name="password"
                label="{{ __('Password') }}"
                type="password"
                required
                autocomplete="current-password"
                placeholder="{{ __('Password') }}"
                :error="$errors->first('password')"
            />

            <x-ui.button type="submit" variant="primary" class="w-full" data-test="confirm-password-button">
                {{ __('Confirm') }}
            </x-ui.button>
        </form>
    </div>
</x-layouts.auth>
