<div class="flex flex-col gap-6">
    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form method="POST" wire:submit="login" class="flex flex-col gap-6">
        <!-- Email Address -->
        <x-ui.input
            id="login-email"
            wire:model="email"
            label="{{ __('Email address') }}"
            type="email"
            required
            autofocus
            autocomplete="email"
            placeholder="email@example.com"
            :error="$errors->first('email')"
        />

        <!-- Password -->
        <div class="relative">
            <x-ui.input
                id="login-password"
                wire:model="password"
                label="{{ __('Password') }}"
                type="password"
                required
                autocomplete="current-password"
                placeholder="{{ __('Password') }}"
                :error="$errors->first('password')"
            />

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" wire:navigate class="absolute end-0 top-0 text-sm text-primary hover:underline">
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
        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-neutral-600 dark:text-neutral-300">
            <span>{{ __('Don\'t have an account?') }}</span>
            <a href="{{ route('register') }}" wire:navigate class="text-primary hover:underline">{{ __('Sign up') }}</a>
        </div>
    @endif
</div>
