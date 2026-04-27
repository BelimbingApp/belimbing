<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Http\Controllers;

use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexAuthManager;
use App\Modules\Core\AI\Services\OpenAiCodexAuth\OpenAiCodexOAuthException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

final class OpenAiCodexOAuthCallbackController
{
    public function __invoke(Request $request): RedirectResponse
    {
        try {
            $provider = app(OpenAiCodexAuthManager::class)->completeCallback($request);
            Session::flash('success', __('OpenAI Codex connected.'));

            return redirect()->route('admin.ai.providers.setup', ['providerKey' => $provider->name]);
        } catch (OpenAiCodexOAuthException $e) {
            Session::flash('error', __('OpenAI Codex sign-in failed: :message', ['message' => $e->getMessage()]));

            return redirect()->route('admin.ai.providers.setup', ['providerKey' => 'openai-codex']);
        }
    }
}
