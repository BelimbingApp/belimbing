<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-6">
            @csrf

            <x-ui.input
                name="email"
                :value="old('email')"
                label="{{ __('Email Address') }}"
                type="email"
                required
                autofocus
                placeholder="email@example.com"
                :error="$errors->first('email')"
            />

            <x-ui.button type="submit" variant="primary" class="w-full" data-test="email-password-reset-link-button">
                {{ __('Email password reset link') }}
            </x-ui.button>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-muted">
            <span>{{ __('Or, return to') }}</span>
            <a href="{{ route('login') }}" class="text-primary hover:underline">{{ __('log in') }}</a>
        </div>
    </div>
</x-layouts.auth>
