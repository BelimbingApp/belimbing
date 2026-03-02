<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register') }}" class="flex flex-col gap-6">
            @csrf

            <x-ui.input
                name="name"
                :value="old('name')"
                label="{{ __('Name') }}"
                type="text"
                required
                autofocus
                autocomplete="name"
                placeholder="{{ __('Full name') }}"
                :error="$errors->first('name')"
            />

            <x-ui.input
                name="email"
                :value="old('email')"
                label="{{ __('Email address') }}"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
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
                <x-ui.button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </x-ui.button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-muted">
            <span>{{ __('Already have an account?') }}</span>
            <a href="{{ route('login') }}" class="text-primary hover:underline">{{ __('Log in') }}</a>
        </div>
    </div>
</x-layouts.auth>
