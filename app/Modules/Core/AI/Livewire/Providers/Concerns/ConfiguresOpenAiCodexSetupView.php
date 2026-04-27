<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Providers\Concerns;

trait ConfiguresOpenAiCodexSetupView
{
    protected function tryAutoConnect(): void
    {
        // OAuth provider: never auto-connect based on base_url; login flow is explicit.
    }

    /**
     * Override: codex credentials are managed by OAuth, not pasted into apiKey.
     *
     * @return array<string, mixed>
     */
    protected function gatherInput(): array
    {
        return [
            'base_url' => $this->baseUrl,
        ];
    }

    protected function mapFieldToProperty(string $fieldKey): string
    {
        return match ($fieldKey) {
            'base_url' => 'baseUrl',
            default => parent::mapFieldToProperty($fieldKey),
        };
    }

    public function providerConnectionDescription(): ?string
    {
        return __('Codex subscription — browser sign-in is required. Belimbing listens on localhost:1455 during sign-in and depends on an undocumented external contract that may break without notice.');
    }

    public function providerHeaderSubtitle(): ?string
    {
        return __('Connect with browser OAuth. Belimbing listens for the callback automatically when possible.');
    }

    public function providerHeaderHelpPartial(): ?string
    {
        return 'livewire.admin.ai.providers.partials.setup-help.openai-codex';
    }

    public function providerConnectedActionsPartial(): ?string
    {
        return 'livewire.admin.ai.providers.partials.connected-actions.openai-codex';
    }

    public function providerStatusPanelPartial(): ?string
    {
        return 'livewire.admin.ai.providers.partials.setup-status.openai-codex';
    }

    public function providerCredentialsFormPartial(): ?string
    {
        return 'livewire.admin.ai.providers.partials.setup-form.openai-codex';
    }
}
