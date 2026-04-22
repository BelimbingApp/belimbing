<div class="space-y-3 text-sm text-muted">
    <div>
        <p class="font-medium text-ink">{{ __('How BLB signs in') }}</p>
        <p>{{ __('BLB opens OpenAI in your browser, OpenAI redirects to a localhost callback URL, and you paste that full URL back into BLB so it can exchange the code and store refreshable subscription credentials securely.') }}</p>
    </div>
    <div>
        <p class="font-medium text-ink">{{ __('What this is not') }}</p>
        <p>{{ __('This provider does not use OpenAI API keys or the public OpenAI API billing path. It uses a compatibility transport observed from OpenAI-owned Codex clients.') }}</p>
    </div>
    <div>
        <p class="font-medium text-ink">{{ __('Support boundary') }}</p>
        <p>{{ __('OpenAI does not publish this as a stable third-party contract. Reconnect or disable the provider if OpenAI changes the flow or backend behavior.') }}</p>
    </div>
</div>
