<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-6">
            @csrf

            <x-ui.input
                name="email"
                :value="old('email')"
                label="{{ __('Email address') }}"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
                :error="$errors->first('email')"
            />

            <div class="relative">
                <x-ui.input
                    name="password"
                    label="{{ __('Password') }}"
                    type="password"
                    required
                    autocomplete="current-password"
                    placeholder="{{ __('Password') }}"
                    :error="$errors->first('password')"
                />

                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="absolute end-0 top-0 text-sm text-primary hover:underline">
                        {{ __('Forgot your password?') }}
                    </a>
                @endif
            </div>

            <div class="flex items-center justify-end">
                <x-ui.button type="submit" variant="primary" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </x-ui.button>
            </div>
        </form>

        @if (Route::has('register'))
            <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-muted">
                <span>{{ __('Don\'t have an account?') }}</span>
                <a href="{{ route('register') }}" class="text-primary hover:underline">{{ __('Sign up') }}</a>
            </div>
        @endif
    </div>
</x-layouts.auth>
