<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Http\Controllers;

use App\Modules\Core\AI\Livewire\Providers\CopilotProxySetup;
use App\Modules\Core\AI\Livewire\Providers\CloudflareGatewaySetup;
use App\Modules\Core\AI\Livewire\Providers\GithubCopilotSetup;
use App\Modules\Core\AI\Livewire\Providers\OpenAiCodexSetup;
use App\Modules\Core\AI\Livewire\Providers\ProviderSetup;
use Illuminate\Http\Request;

/**
 * Resolves and renders the correct Livewire full-page component for provider setup.
 *
 * Routes cannot use Livewire component classes directly when the class must be
 * chosen dynamically at runtime, so this controller mirrors LivewirePageController:
 * it resolves the component class from the route parameter, instantiates it via
 * the Livewire factory, and delegates rendering to the component's __invoke method.
 */
class ProviderSetupController
{
    public function __invoke(Request $request): mixed
    {
        $providerKey = $request->route('providerKey');

        $componentClass = match ($providerKey) {
            'copilot-proxy' => CopilotProxySetup::class,
            'cloudflare-ai-gateway' => CloudflareGatewaySetup::class,
            'github-copilot' => GithubCopilotSetup::class,
            'openai-codex' => OpenAiCodexSetup::class,
            default => ProviderSetup::class,
        };

        $instance = app('livewire')->new($componentClass);

        return app()->call([$instance, '__invoke']);
    }
}
