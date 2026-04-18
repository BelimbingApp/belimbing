<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ProviderExecutionCapabilities;
use App\Base\AI\DTO\SamplingControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\ReasoningEffort;
use App\Base\AI\Enums\ReasoningVisibility;
use App\Base\AI\Enums\ToolChoiceMode;

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

        if ($apiType === AiApiType::AnthropicMessages && $this->isAnthropicProvider($providerName)) {
            return new ProviderExecutionCapabilities(
                supportedReasoningVisibility: [ReasoningVisibility::None, ReasoningVisibility::Summary],
                supportedReasoningEffort: $this->supportsAnthropicAdaptiveThinking($model)
                    ? [ReasoningEffort::Low, ReasoningEffort::Medium, ReasoningEffort::High]
                    : [],
                supportsReasoningBudget: true,
                supportsReasoningContextPreservation: true,
                supportedToolChoiceModesWhenReasoning: [ToolChoiceMode::Auto, ToolChoiceMode::None],
                supportsNativeReasoningBlocks: true,
                supportsAdaptiveReasoning: $this->supportsAnthropicAdaptiveThinking($model),
                defaultReasoningBudget: 2048,
                interleavedThinkingBetaHeader: 'interleaved-thinking-2025-05-14',
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

        if ($this->isMoonshotProvider($providerName)) {
            return new ProviderExecutionCapabilities(
                requiresAnyOfToolSchemas: true,
            );
        }

        return new ProviderExecutionCapabilities;
    }

    private function isMoonshotProvider(?string $providerName): bool
    {
        return in_array($providerName, ['moonshotai', 'moonshotai-cn', 'kimi-for-coding'], true);
    }

    private function isAnthropicProvider(?string $providerName): bool
    {
        return $providerName === 'anthropic';
    }

    private function supportsAnthropicAdaptiveThinking(string $model): bool
    {
        return str_starts_with($model, 'claude-opus-4-6')
            || str_starts_with($model, 'claude-sonnet-4-6')
            || str_starts_with($model, 'claude-mythos');
    }
}
