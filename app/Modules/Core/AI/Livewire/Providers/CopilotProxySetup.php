<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class CopilotProxySetup extends ProviderSetup
{
    public ?string $baseUrlStatus = null;

    public string $baseUrlStatusMessage = '';

    public function providerConnectionDescription(): ?string
    {
        return __('Requires the Copilot Proxy extension running in VS Code — start the extension, then connect.');
    }

    public function providerCredentialsFormPartial(): ?string
    {
        return 'livewire.admin.ai.providers.partials.setup-form.copilot-proxy';
    }

    /**
     * Pre-set the checking state so the spinner is visible on first render.
     * The actual HTTP probe is deferred via wire:init="checkBaseUrl" in the template.
     */
    protected function setUpProvider(): void
    {
        if ($this->baseUrl !== '') {
            $this->baseUrlStatus = 'checking';
            $this->baseUrlStatusMessage = __('Checking connection...');
        }
    }

    public function checkBaseUrl(): void
    {
        if ($this->baseUrl === '') {
            $this->baseUrlStatus = null;
            $this->baseUrlStatusMessage = '';

            return;
        }

        $this->baseUrlStatus = 'checking';
        $this->baseUrlStatusMessage = __('Checking connection...');

        try {
            $response = Http::timeout(5)
                ->get(rtrim($this->baseUrl, '/').'/models');

            if ($response->successful()) {
                $this->baseUrlStatus = 'online';
                $this->baseUrlStatusMessage = __('Server is online');
                $this->autoConnect();
            } else {
                $this->baseUrlStatus = 'offline';
                $this->baseUrlStatusMessage = __('Server returned: :status', ['status' => $response->status()]);
            }
        } catch (ConnectionException $e) {
            $this->baseUrlStatus = 'offline';
            $this->baseUrlStatusMessage = __('Cannot connect — is the server running?');
        } catch (\Exception $e) {
            $this->baseUrlStatus = 'offline';
            $this->baseUrlStatusMessage = __('Connection failed: :message', ['message' => $e->getMessage()]);
        }
    }

    public function updatedBaseUrl(): void
    {
        $this->checkBaseUrl();
    }

    /**
     * Auto-connect when the proxy is confirmed online.
     *
     * Skips validation (base URL is verified by the HTTP probe, copilot-proxy
     * uses auth_type 'none' so no API key is required) and connects directly.
     */
    private function autoConnect(): void
    {
        if ($this->connectedProviderId !== null) {
            return;
        }

        $this->connect();
    }
}
