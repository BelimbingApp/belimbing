<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Providers\Help;

final class CopilotProxyHelp implements ProviderHelpContract
{
    public function setupSteps(): array
    {
        return [
            __('Install the "Copilot Proxy" extension in VS Code (search for "Copilot Proxy" in the Extensions panel).'),
            __('Open VS Code and ensure you are signed in to GitHub Copilot (check the account icon in the bottom-left corner).'),
            __('Start the proxy via the extension — it listens on :url by default.', ['url' => 'http://localhost:1337']),
            __('Return to Belimbing and click "Update Models" — the proxy will report the models available through your Copilot subscription.'),
        ];
    }

    public function troubleshootingTips(): array
    {
        return [
            __('Make sure VS Code is open and the Copilot Proxy extension is active (check the status bar for a proxy indicator).'),
            __('Confirm you have an active GitHub Copilot Individual or Business subscription at github.com/settings/copilot.'),
            __('If the extension was just installed, restart VS Code and try again.'),
            __('Check that nothing else is using port 1337 — if so, reconfigure the extension\'s port and update the Base URL here to match.'),
            __('If models are empty after connecting, your Copilot plan may not include API access. Try the GitHub Copilot provider instead (uses device login).'),
        ];
    }

    public function documentationUrl(): ?string
    {
        return 'https://marketplace.visualstudio.com/items?itemName=aeac.copilot-proxy';
    }

    public function connectionErrorAdvice(): string
    {
        return __('The Copilot Proxy extension is not reachable. Open VS Code, ensure the extension is installed and running, then try again.');
    }
}
