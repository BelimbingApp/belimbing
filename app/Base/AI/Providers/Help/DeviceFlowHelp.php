<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Providers\Help;

final class DeviceFlowHelp implements ProviderHelpContract
{
    public function setupSteps(): array
    {
        return [
            __('Browse AI Providers and click "Connect" on this provider.'),
            __('On the setup page, click the sign-in button — a one-time device code will appear.'),
            __('Open the verification URL shown on screen and enter the device code.'),
            __('Approve the authorization request in your browser.'),
            __('BLB will detect the approval automatically and complete the connection.'),
        ];
    }

    public function troubleshootingTips(): array
    {
        return [
            __('Device codes expire after a short time (~15 minutes). If the code expired, remove and re-add the provider to start a fresh flow.'),
            __('Your subscription or account may have lapsed — check the provider\'s dashboard to confirm your plan is active.'),
        ];
    }

    public function documentationUrl(): ?string
    {
        return null;
    }

    public function connectionErrorAdvice(): string
    {
        return __('Your authorization token may have expired. Re-add this provider via "Browse AI Providers" to re-authorize.');
    }
}
