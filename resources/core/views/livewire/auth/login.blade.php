<div
    x-data="{ redirecting: false }"
    @login-redirecting.window="
        redirecting = true;
        setTimeout(() => $refs.loginButton.disabled = true);
        setTimeout(() => window.location.assign($event.detail.url), 100);
    "
    class="flex flex-col gap-6"
>
    @if ($showSessionExpiredNotice)
        <x-ui.alert variant="warning">
            {{ __('Your session expired. Sign in again to continue.') }}
        </x-ui.alert>
    @endif

    <x-ui.alert x-cloak x-show="redirecting" variant="info" role="status">
        {{ __('Signed in. Opening your workspace…') }}
    </x-ui.alert>

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
            <x-ui.button
                type="submit"
                variant="primary"
                class="w-full"
                data-test="login-button"
                x-ref="loginButton"
                wire:loading.attr="disabled"
                wire:target="login"
                x-bind:disabled="redirecting"
            >
                <span wire:loading.remove wire:target="login" x-show="!redirecting">{{ __('Log in') }}</span>
                <span wire:loading.remove wire:target="login" x-cloak x-show="redirecting">{{ __('Opening workspace…') }}</span>
                <span wire:loading wire:target="login">{{ __('Signing in…') }}</span>
            </x-ui.button>
        </div>
    </form>

</div>
