<div
    x-data
    x-on:openai-codex-oauth-opened.window="window.open($event.detail.url, '_blank', 'noopener,noreferrer')"
    class="space-y-4"
>
    <x-ui.alert variant="warning">
        {{ __('Browser sign-in required. OpenAI Codex uses subscription-backed ChatGPT credentials, not OpenAI API keys. BLB now emulates the OpenClaw localhost callback flow, which depends on an undocumented external contract and may break without notice.') }}
    </x-ui.alert>

    <x-ui.alert variant="info">
        {{ __('1. Click Sign in to open OpenAI in a new tab. 2. Finish the browser sign-in. 3. When the browser lands on :callback, copy the full URL from the address bar and paste it below.', ['callback' => $this->oauthRedirectUri]) }}
    </x-ui.alert>

    <x-ui.input
        id="provider-base-url"
        wire:model.live.blur="baseUrl"
        label="{{ __('Base URL') }}"
        required
        :error="$errors->first('baseUrl')"
    />

    <x-ui.textarea
        id="openai-codex-manual-redirect"
        wire:model.live="manualRedirectInput"
        label="{{ __('Paste localhost callback URL') }}"
        rows="3"
        :error="$manualCompletionError"
    />

    @if(($this->authState['status'] ?? null) === 'pending')
        <p class="text-sm text-muted">
            {{ __('Sign-in is waiting for the localhost callback result. Paste the full :callback URL here to finish the token exchange.', ['callback' => $this->oauthRedirectUri]) }}
        </p>
    @endif

    <div class="flex flex-wrap justify-end gap-2">
        <x-ui.button variant="primary" wire:click="startOauthLogin">
            <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-4 h-4" />
            {{ __('Sign in') }}
        </x-ui.button>
        <x-ui.button variant="secondary" wire:click="completeOauthLogin">
            <x-icon name="heroicon-o-check-circle" class="w-4 h-4" />
            {{ __('Complete sign-in') }}
        </x-ui.button>
    </div>
</div>
