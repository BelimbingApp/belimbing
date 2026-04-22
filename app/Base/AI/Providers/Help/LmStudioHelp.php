<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Providers\Help;

final class LmStudioHelp implements ProviderHelpContract
{
    public function setupSteps(): array
    {
        return [
            __('Download and install LM Studio from lmstudio.ai.'),
            __('Open LM Studio and download a model from the Discover tab (search for a model name and click Download).'),
            __('Go to the "Local Server" tab (the ↔ icon in the left sidebar) and load your downloaded model.'),
            __('Click "Start Server" — LM Studio will listen on port 1234 by default.'),
            __('Click "Update Models" in Belimbing to detect available models from LM Studio.'),
        ];
    }

    public function troubleshootingTips(): array
    {
        return [
            __('Make sure the LM Studio Local Server is started and shows a green status — it does not start automatically on launch.'),
            __('Ensure a model is loaded in the Local Server tab; an unloaded server returns no models.'),
            __('If you changed the server port in LM Studio preferences, update the Base URL here to match.'),
            __('LM Studio requires a reasonably modern GPU or CPU with enough RAM to run the model — check LM Studio\'s status bar for memory warnings.'),
        ];
    }

    public function documentationUrl(): ?string
    {
        return 'https://lmstudio.ai/docs/local-server';
    }

    public function connectionErrorAdvice(): string
    {
        return __('LM Studio\'s local server is not running. Open LM Studio, go to the Local Server tab, load a model, and click "Start Server".');
    }
}
