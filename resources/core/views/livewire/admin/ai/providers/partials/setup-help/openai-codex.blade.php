<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

<div class="space-y-3 text-sm text-muted">
    <div>
        <p class="font-medium text-ink">{{ __('How BLB signs in') }}</p>
        <p>{{ __('BLB opens OpenAI in your browser and listens on localhost:1455 for the callback. When OpenAI redirects after sign-in, BLB captures the authorization code automatically and stores refreshable subscription credentials securely.') }}</p>
    </div>
    <div>
        <p class="font-medium text-ink">{{ __('Port 1455 conflict') }}</p>
        <p>{{ __('If another tool (OpenClaw, Codex CLI, Pi) is using port 1455, BLB cannot listen automatically. Stop the conflicting tool or paste the callback URL manually using the fallback option.') }}</p>
    </div>
    <div>
        <p class="font-medium text-ink">{{ __('What this is not') }}</p>
        <p>{{ __('This provider does not use OpenAI API keys or the public OpenAI API billing path. It uses a compatibility transport observed from OpenAI-owned Codex clients.') }}</p>
    </div>
    <div>
        <p class="font-medium text-ink">{{ __('Support boundary') }}</p>
        <p>{{ __('OpenAI does not publish this as a stable third-party contract. Reconnect or disable the provider if OpenAI changes the flow or backend behavior.') }}</p>
    </div>
</div>
