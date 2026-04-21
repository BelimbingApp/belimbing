<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
    <div>
        <x-ui.input
            id="provider-base-url"
            wire:model.live.blur="baseUrl"
            label="{{ __('Base URL') }}"
            required
            :error="$errors->first('baseUrl')"
        />
    </div>

    <div>
        <x-ui.input
            id="provider-api-key"
            wire:model.live.blur="apiKey"
            type="password"
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
</div>
