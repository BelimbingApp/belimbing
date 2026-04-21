<div class="space-y-3">
    <x-ui.alert variant="warning">
        {{ __('Browser sign-in required. OpenAI Codex uses subscription-backed ChatGPT credentials, not OpenAI API keys. This integration depends on an undocumented external contract and may break without notice.') }}
    </x-ui.alert>
    <x-ui.input
        id="provider-base-url"
        wire:model.live.blur="baseUrl"
        label="{{ __('Base URL') }}"
        required
        :error="$errors->first('baseUrl')"
    />
    <div class="flex justify-end">
        <x-ui.button variant="primary" wire:click="startOauthLogin">
            <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-4 h-4" />
            {{ __('Sign in') }}
        </x-ui.button>
    </div>
</div>
