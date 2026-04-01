<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\Services\ProviderTestService;

/**
 * Adds provider connectivity testing to agent setup pages.
 *
 * Requires ManagesAgentModelSelection to be used in the same component
 * for access to $selectedProviderId, $selectedModelId, and validateProviderAndModel().
 */
trait HandlesProviderDiagnostics
{
    /**
     * Serialized test result array, or null if no test has been run.
     *
     * @var array{connected: bool, provider_name: string, model: string, latency_ms: ?int, error_type: ?string, user_message: ?string, hint: ?string}|null
     */
    public ?array $providerTestResult = null;

    /**
     * Run an end-to-end connectivity test for the currently selected provider and model.
     */
    public function testProvider(): void
    {
        $this->providerTestResult = null;

        $this->validateProviderAndModel();

        $result = app(ProviderTestService::class)->testSelection(
            providerId: (int) $this->selectedProviderId,
            modelId: (string) $this->selectedModelId,
        );

        $this->providerTestResult = $result->toArray();
    }

    /**
     * Clear stale test results when the provider or model selection changes.
     */
    protected function clearProviderTestResult(): void
    {
        $this->providerTestResult = null;
    }
}
