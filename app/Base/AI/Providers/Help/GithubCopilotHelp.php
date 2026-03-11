<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Providers\Help;

final class GithubCopilotHelp implements ProviderHelpContract
{
    public function setupSteps(): array
    {
        return [
            __('Ensure you have an active GitHub Copilot Individual or Business subscription at github.com/settings/copilot.'),
            __('Browse AI Providers and click "Connect" on "GitHub Copilot".'),
            __('On the setup page, click "Sign in with GitHub" — a device code will be displayed.'),
            __('Open the verification URL shown on screen, enter the device code, and approve the access request.'),
            __('BLB will detect the approval automatically and import the available models.'),
        ];
    }

    public function troubleshootingTips(): array
    {
        return [
            __('Your authorization token may have expired. Remove and re-add GitHub Copilot via "Browse AI Providers" to get a fresh token.'),
            __('Ensure your Copilot subscription is active and not paused — check github.com/settings/copilot.'),
            __('If device flow authorization timed out (codes expire after ~15 minutes), start the wizard again and approve promptly.'),
            __('Enterprise accounts may require an administrator to enable Copilot API access.'),
        ];
    }

    public function documentationUrl(): ?string
    {
        return 'https://docs.github.com/en/copilot/using-github-copilot/using-github-copilot-in-your-ide/using-github-copilot-in-visual-studio-code';
    }

    public function connectionErrorAdvice(): string
    {
        return __('Your GitHub Copilot token may have expired. Re-add the provider via "Browse AI Providers" to re-authorize.');
    }
}
