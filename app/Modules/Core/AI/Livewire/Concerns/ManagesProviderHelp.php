<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Base\AI\Providers\Help\ProviderHelpRegistry;

/**
 * Provider help panel state and actions for the provider manager component.
 *
 * Manages the toggle/close lifecycle and structured help content retrieval
 * for the inline setup & troubleshooting panel.
 */
trait ManagesProviderHelp
{
    public ?string $helpProviderKey = null;

    public ?string $helpProviderAuthType = null;

    public ?string $helpPanelScope = null;

    /**
     * Open the provider help panel (toggle behavior).
     */
    public function openProviderHelp(string $providerKey, string $authType = 'api_key', string $scope = 'connected'): void
    {
        if ($this->helpProviderKey === $providerKey && $this->helpPanelScope === $scope) {
            $this->helpProviderKey = null;
            $this->helpProviderAuthType = null;
            $this->helpPanelScope = null;

            return;
        }

        $this->helpProviderKey = $providerKey;
        $this->helpProviderAuthType = $authType;
        $this->helpPanelScope = $scope;
    }

    /**
     * Close the provider help panel.
     */
    public function closeProviderHelp(): void
    {
        $this->helpProviderKey = null;
        $this->helpProviderAuthType = null;
        $this->helpPanelScope = null;
    }

    /**
     * Return structured help content for the currently open help panel.
     *
     * @return array{setup_steps: list<string>, troubleshooting_tips: list<string>, documentation_url: string|null, connection_error_advice: string}|null
     */
    public function activeProviderHelp(): ?array
    {
        if ($this->helpProviderKey === null) {
            return null;
        }

        $help = app(ProviderHelpRegistry::class)->get($this->helpProviderKey, $this->helpProviderAuthType);

        return [
            'setup_steps' => $help->setupSteps(),
            'troubleshooting_tips' => $help->troubleshootingTips(),
            'documentation_url' => $help->documentationUrl(),
            'connection_error_advice' => $help->connectionErrorAdvice(),
        ];
    }
}
