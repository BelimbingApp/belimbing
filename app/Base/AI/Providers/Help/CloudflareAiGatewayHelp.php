<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Providers\Help;

final class CloudflareAiGatewayHelp implements ProviderHelpContract
{
    public function setupSteps(): array
    {
        return [
            __('Log in to the Cloudflare dashboard at dash.cloudflare.com.'),
            __('Navigate to AI → AI Gateway and click "Create Gateway". Give it a name (e.g. "blb-gateway").'),
            __('Copy your Account ID (found in the right sidebar on any Cloudflare page) and your Gateway ID (shown after creation).'),
            __('In Belimbing, enter those two values — the base URL will be computed automatically.'),
            __('Optionally set a Cloudflare API token with "AI Gateway Read" permissions for authenticated requests.'),
        ];
    }

    public function troubleshootingTips(): array
    {
        return [
            __('Double-check that the Account ID and Gateway ID are correct — a single wrong character causes connection failures.'),
            __('AI Gateway is a proxy — you still need to configure the upstream providers (OpenAI, Anthropic, etc.) in their respective dashboards.'),
            __('If you see authentication errors, verify your Cloudflare API token has the "AI Gateway" permission scope.'),
            __('Ensure the gateway is not paused or rate-limited in the Cloudflare dashboard.'),
        ];
    }

    public function documentationUrl(): ?string
    {
        return 'https://developers.cloudflare.com/ai-gateway/get-started/';
    }

    public function connectionErrorAdvice(): string
    {
        return __('Check that your Account ID and Gateway ID are correct in the provider settings, and that the Cloudflare AI Gateway is active in your Cloudflare dashboard.');
    }
}
