<?php
namespace App\Modules\Core\AI\Livewire\Providers;

class GithubCopilotSetup extends ProviderSetup
{
    /**
     * Poll the device flow and auto-connect on success.
     *
     * Overrides parent to skip the manual "Connect & Import Models" step —
     * once GitHub authorizes the device code, we connect immediately and
     * transition to the inline model table.
     */
    public function pollDeviceFlow(): void
    {
        parent::pollDeviceFlow();

        if ($this->deviceFlow['status'] === 'success' && $this->connectedProviderId === null) {
            $this->connect();
        }
    }
}
