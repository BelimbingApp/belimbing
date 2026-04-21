<div class="space-y-4">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-ui.input
            id="cloudflare-account-id"
            wire:model="cloudflareAccountId"
            label="{{ __('Account ID') }}"
            required
            placeholder="{{ __('Cloudflare Account ID') }}"
            :error="$errors->first('cloudflareAccountId')"
        />
        <x-ui.input
            id="cloudflare-gateway-id"
            wire:model.live.blur="cloudflareGatewayId"
            label="{{ __('Gateway ID') }}"
            required
            placeholder="{{ __('AI Gateway name') }}"
            :error="$errors->first('cloudflareGatewayId')"
        />
    </div>
    <x-ui.input
        id="cloudflare-api-key"
        wire:model.live.blur="apiKey"
        type="password"
        label="{{ __('API Key') }}"
        required
        placeholder="{{ __('Cloudflare API token') }}"
        :error="$errors->first('apiKey')"
    />
    @if($this->maskedApiKey)
        <p class="mt-1 font-mono text-xs text-muted">{{ $this->maskedApiKey }}</p>
    @endif
    <p class="text-xs text-muted">{{ __('The base URL will be computed as: gateway.ai.cloudflare.com/v1/{account_id}/{gateway_id}/openai') }}</p>
</div>
