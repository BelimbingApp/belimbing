<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Providers\Concerns;

use App\Modules\Core\AI\Enums\AuthType;

trait HasProviderSetupPresentation
{
    public function providerConnectionDescription(): ?string
    {
        return match ($this->authType) {
            'local' => __('Local server — API key is optional'),
            'oauth' => __('OAuth provider — requires a dedicated sign-in flow, not the generic API-key setup path'),
            'subscription' => __('Subscription service — paste access token or API key'),
            'custom' => __('Requires additional configuration after connecting'),
            'device_flow' => __('Requires GitHub device login — an active GitHub Copilot subscription is needed'),
            default => null,
        };
    }

    public function providerHeaderSubtitle(): ?string
    {
        return null;
    }

    public function providerHeaderHelpPartial(): ?string
    {
        return null;
    }

    public function providerConnectedActionsPartial(): ?string
    {
        return null;
    }

    public function providerStatusPanelPartial(): ?string
    {
        return null;
    }

    public function providerCredentialsFormPartial(): ?string
    {
        return $this->authType === AuthType::OAuth->value
            ? 'livewire.admin.ai.providers.partials.setup-form.oauth-unavailable'
            : null;
    }
}
