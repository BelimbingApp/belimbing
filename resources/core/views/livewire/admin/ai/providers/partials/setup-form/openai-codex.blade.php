<?php
/** @var \App\Modules\Core\AI\Livewire\Providers\OpenAiCodexSetup $this */
?>

<div
    x-data
    x-on:openai-codex-oauth-opened.window="window.open($event.detail.url, '_blank', 'noopener,noreferrer')"
    class="space-y-4"
>
    <x-ui.alert variant="warning">
        {{ __('Browser sign-in required. OpenAI Codex uses subscription-backed ChatGPT credentials, not OpenAI API keys. This depends on an undocumented external contract and may break without notice.') }}
    </x-ui.alert>

    <x-ui.input
        id="provider-base-url"
        wire:model.live.blur="baseUrl"
        label="{{ __('Base URL') }}"
        required
        :error="$errors->first('baseUrl')"
    />

    @if(($this->authState['status'] ?? null) === 'pending')
        <div wire:poll.3s class="space-y-4">
            @if($this->listenerSpawned)
                <x-ui.alert variant="info">
                    {{ __('Finish the browser sign-in. Belimbing is listening for the callback and will connect automatically.') }}
                </x-ui.alert>
                <div class="flex items-center gap-2 text-sm text-muted">
                    <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    {{ __('Waiting for OpenAI callback on localhost:1455…') }}
                </div>
            @else
                <x-ui.alert variant="info">
                    {{ __('Port 1455 is in use (OpenClaw, Codex CLI, or another tool). Finish the browser sign-in, then copy the full URL from the address bar and paste it below.') }}
                </x-ui.alert>
            @endif

            {{-- Manual paste fallback — always available when pending.
                 Uses Alpine x-show (not <details>) so the toggle state survives
                 the wire:poll re-render above. Default-open when the listener
                 did not spawn, since manual paste is then the only path. --}}
            <div x-data="{ open: @js(! $this->listenerSpawned) }">
                <button
                    type="button"
                    x-on:click="open = !open"
                    class="flex items-center gap-1 text-sm font-medium text-accent hover:underline"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 transition-transform" :class="open && 'rotate-90'" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                    </svg>
                    {{ __('Paste callback URL manually') }}
                </button>
                <div x-show="open" x-cloak class="mt-2 space-y-2">
                    <x-ui.textarea
                        id="openai-codex-manual-redirect"
                        wire:model.live="manualRedirectInput"
                        label="{{ __('Paste localhost callback URL') }}"
                        rows="2"
                        :error="$manualCompletionError"
                    />
                    <div class="flex justify-end">
                        <x-ui.button variant="secondary" wire:click="completeOauthLogin" size="sm">
                            <x-icon name="heroicon-o-check-circle" class="h-4 w-4" />
                            {{ __('Complete sign-in') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="flex flex-wrap justify-end gap-2">
        <x-ui.button variant="primary" wire:click="startOauthLogin">
            <x-icon name="heroicon-o-arrow-top-right-on-square" class="h-4 w-4" />
            {{ __('Sign in') }}
        </x-ui.button>
    </div>
</div>
