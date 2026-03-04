<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Providers\Help;

final class LocalServerHelp implements ProviderHelpContract
{
    public function setupSteps(): array
    {
        return [
            __('Start your local inference server according to its own documentation.'),
            __('Confirm the server is listening on the Base URL shown in the provider settings.'),
            __('Click "Update Models" to discover available models from the running server.'),
        ];
    }

    public function troubleshootingTips(): array
    {
        return [
            __('Ensure the server process is running and has fully started up before connecting.'),
            __('Check that the Base URL port matches what the server is actually listening on.'),
            __('Firewall or VPN software may block localhost connections — temporarily disable them to test.'),
            __('Check the server\'s own logs for startup errors or missing model files.'),
        ];
    }

    public function documentationUrl(): ?string
    {
        return null;
    }

    public function connectionErrorAdvice(): string
    {
        return __('The local server is not reachable. Ensure it is running and listening on the Base URL shown in the provider settings.');
    }
}
