<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Providers\Help;

final class DefaultProviderHelp implements ProviderHelpContract
{
    public function setupSteps(): array
    {
        return [
            __('Ensure the provider endpoint (Base URL) is reachable from this server.'),
            __('Enter any required API key or credentials in the provider settings.'),
            __('Click "Update Models" to discover available models.'),
        ];
    }

    public function troubleshootingTips(): array
    {
        return [
            __('Verify the Base URL is correct and the provider service is running.'),
            __('Check that your API key or credentials are valid.'),
            __('Confirm there are no firewall rules blocking outbound connections to the provider endpoint.'),
        ];
    }

    public function documentationUrl(): ?string
    {
        return null;
    }

    public function connectionErrorAdvice(): string
    {
        return __('Cannot reach the provider endpoint. Check the Base URL in the provider settings and ensure the service is running and accessible.');
    }
}
