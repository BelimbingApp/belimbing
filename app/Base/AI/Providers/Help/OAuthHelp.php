<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Providers\Help;

final class OAuthHelp implements ProviderHelpContract
{
    public function setupSteps(): array
    {
        return [
            __('This provider requires a dedicated OAuth sign-in flow rather than a pasted API key.'),
            __('If BLB offers a provider-specific setup page for this provider, use that sign-in flow instead of the generic connect form.'),
            __('If BLB does not yet implement the provider-specific OAuth flow, leave the provider disconnected until that support exists.'),
        ];
    }

    public function troubleshootingTips(): array
    {
        return [
            __('Do not paste arbitrary bearer tokens into the generic setup form unless BLB explicitly documents that fallback for the provider.'),
            __('If sign-in used to work and now fails, the provider may have changed its OAuth or backend contract.'),
            __('When OAuth support is missing or unstable, disable the provider and use an API-key-backed provider instead.'),
        ];
    }

    public function documentationUrl(): ?string
    {
        return null;
    }

    public function connectionErrorAdvice(): string
    {
        return __('This provider needs a dedicated OAuth sign-in flow. Use the provider-specific setup when available, or leave the provider disconnected until BLB supports it.');
    }
}
