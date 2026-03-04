<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Providers\Help;

final class ApiKeyHelp implements ProviderHelpContract
{
    public function setupSteps(): array
    {
        return [
            __('Obtain an API key from the provider\'s developer dashboard (use the "Get API Key" link on the connect step).'),
            __('Paste the API key into the field on the connect step and click "Connect All".'),
            __('Click "Update Models" to import available models from this provider.'),
        ];
    }

    public function troubleshootingTips(): array
    {
        return [
            __('Verify the API key is correct and has not been revoked in the provider\'s dashboard.'),
            __('Some providers require billing to be set up before the API key works — check your account status.'),
            __('Check that your API key has the necessary permissions (some providers issue read-only or scoped keys).'),
            __('If the Base URL was changed, reset it to the provider\'s default endpoint.'),
        ];
    }

    public function documentationUrl(): ?string
    {
        return null;
    }

    public function connectionErrorAdvice(): string
    {
        return __('Check your network connection and verify the provider\'s API endpoint is reachable. If the error persists, confirm your API key is valid in the provider\'s dashboard.');
    }
}
