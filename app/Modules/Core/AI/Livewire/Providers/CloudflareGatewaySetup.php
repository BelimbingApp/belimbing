<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Providers;

class CloudflareGatewaySetup extends ProviderSetup
{
    public string $cloudflareAccountId = '';

    public string $cloudflareGatewayId = '';

    public function providerHeaderHelpPartial(): ?string
    {
        return 'livewire.admin.ai.providers.partials.setup-help.cloudflare-ai-gateway';
    }

    public function providerCredentialsFormPartial(): ?string
    {
        return 'livewire.admin.ai.providers.partials.setup-form.cloudflare-ai-gateway';
    }

    /**
     * Auto-connect when the Cloudflare Gateway ID is updated (blur).
     *
     * Overrides parent behavior because Cloudflare uses Account ID + Gateway ID
     * + API key, not the generic base URL + API key shape.
     */
    public function updatedCloudflareGatewayId(): void
    {
        if ($this->connectedProviderId !== null) {
            return;
        }

        $this->tryAutoConnect();
    }

    /**
     * Attempt auto-connect for Cloudflare's custom credential shape.
     *
     * Overrides parent to require Account ID + Gateway ID + API key before
     * creating the provider record and running model discovery.
     */
    protected function tryAutoConnect(): void
    {
        if ($this->cloudflareAccountId === '' || $this->cloudflareGatewayId === '' || $this->apiKey === '') {
            return;
        }

        $this->connect();
    }

    /**
     * Gather Cloudflare-specific input for the definition.
     *
     * Overrides parent to provide account_id and gateway_id instead of base_url.
     * The CloudflareGatewayDefinition derives the base URL from these.
     *
     * @return array<string, mixed>
     */
    protected function gatherInput(): array
    {
        return [
            'account_id' => $this->cloudflareAccountId,
            'gateway_id' => $this->cloudflareGatewayId,
            'api_key' => $this->apiKey,
        ];
    }

    /**
     * Map Cloudflare definition field keys to Livewire property names.
     */
    protected function mapFieldToProperty(string $fieldKey): string
    {
        return match ($fieldKey) {
            'account_id' => 'cloudflareAccountId',
            'gateway_id' => 'cloudflareGatewayId',
            default => parent::mapFieldToProperty($fieldKey),
        };
    }
}
