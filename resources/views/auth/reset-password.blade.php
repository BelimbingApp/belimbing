<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Reset password')" :description="__('Please enter your new password below')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-6">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <x-ui.input
                name="email"
                :value="old('email', $email)"
                label="{{ __('Email') }}"
                type="email"
                required
                autocomplete="email"
                :error="$errors->first('email')"
            />

            <x-ui.input
                name="password"
                label="{{ __('Password') }}"
                type="password"
                required
                autocomplete="new-password"
                placeholder="{{ __('Password') }}"
                :error="$errors->first('password')"
            />

            <x-ui.input
                name="password_confirmation"
                label="{{ __('Confirm password') }}"
                type="password"
                required
                autocomplete="new-password"
                placeholder="{{ __('Confirm password') }}"
                :error="$errors->first('password_confirmation')"
            />

            <div class="flex items-center justify-end">
                <x-ui.button type="submit" variant="primary" class="w-full" data-test="reset-password-button">
                    {{ __('Reset password') }}
                </x-ui.button>
            </div>
        </form>
    </div>
</x-layouts.auth>
