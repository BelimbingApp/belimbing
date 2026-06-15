<div class="space-y-4">
    <x-ui.input
        id="provider-base-url"
        wire:model.live.blur="baseUrl"
        :label="$authType === 'local' ? __('Base URL') : __('Base URL (from provider catalog)')"
        required
        :error="$errors->first('baseUrl')"
    />

    <x-ui.secret-input
        id="provider-api-key"
        wire:model.blur="apiKey"
        :label="in_array($authType, ['local', 'subscription']) ? __('API Key (optional)') : __('API Key')"
        :required="in_array($authType, ['api_key', 'custom'])"
        :placeholder="match($authType) {
            'local' => __('Leave empty for local servers'),
            'subscription' => __('Paste access token'),
            default => __('Paste your API key'),
        }"
        :error="$errors->first('apiKey')"
    />
    @if($this->maskedApiKey)
        <p class="mt-1 font-mono text-xs text-muted">{{ $this->maskedApiKey }}</p>
    @endif
</div>
