<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ProviderExecutionCapabilities;
use App\Base\AI\DTO\SamplingControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\ReasoningEffort;
use App\Base\AI\Enums\ReasoningVisibility;

final class ProviderCapabilityRegistry
{
    public function capabilitiesFor(?string $providerName, string $model, AiApiType $apiType): ProviderExecutionCapabilities
    {
        if ($apiType === AiApiType::OpenAiResponses) {
            return new ProviderExecutionCapabilities(
                supportedReasoningVisibility: [ReasoningVisibility::None, ReasoningVisibility::Summary],
                supportedReasoningEffort: [ReasoningEffort::Low, ReasoningEffort::Medium, ReasoningEffort::High],
                supportsReasoningBudget: true,
                supportsReasoningContextPreservation: true,
            );
        }

        if ($this->isMoonshotProvider($providerName) && str_contains($model, 'kimi-k2.5')) {
            return new ProviderExecutionCapabilities(
                requiresAnyOfToolSchemas: true,
                fixedSamplingWhenReasoningEnabled: new SamplingControls(
                    temperature: 1.0,
                    topP: 0.95,
                    candidateCount: 1,
                    presencePenalty: 0.0,
                    frequencyPenalty: 0.0,
                ),
                fixedSamplingWhenReasoningDisabled: new SamplingControls(
                    temperature: 0.6,
                    topP: 0.95,
                    candidateCount: 1,
                    presencePenalty: 0.0,
                    frequencyPenalty: 0.0,
                ),
                supportedReasoningVisibility: [ReasoningVisibility::None, ReasoningVisibility::Full],
                supportsReasoningContextPreservation: true,
            );
        }

        return new ProviderExecutionCapabilities;
    }

    private function isMoonshotProvider(?string $providerName): bool
    {
        return in_array($providerName, ['moonshotai', 'moonshotai-cn', 'kimi-for-coding'], true);
    }
}
