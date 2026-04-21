<div class="space-y-2 text-sm text-muted">
    <p class="text-ink font-medium">{{ __('What this is') }}</p>
    <p>{{ __('Cloudflare AI Gateway is a gateway/proxy layer in front of model providers (for example OpenAI). It is not the model provider itself.') }}</p>

    <p class="text-ink font-medium pt-1">{{ __('Why use it') }}</p>
    <ul class="list-disc list-inside space-y-1">
        <li>{{ __('Single endpoint for routing and failover') }}</li>
        <li>{{ __('Centralized observability, rate limits, and governance') }}</li>
        <li>{{ __('Provider changes without app-side endpoint rewiring') }}</li>
    </ul>

    <p class="text-ink font-medium pt-1">{{ __('What you need') }}</p>
    <ul class="list-disc list-inside space-y-1">
        <li>{{ __('A Cloudflare account') }}</li>
        <li>{{ __('Cloudflare Account ID and AI Gateway ID from your Cloudflare dashboard') }}</li>
        <li>{{ __('API credentials for the upstream provider (for example OpenAI) configured in your gateway flow') }}</li>
    </ul>
</div>
