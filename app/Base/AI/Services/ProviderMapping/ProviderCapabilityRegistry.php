<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ProviderExecutionCapabilities;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\ReasoningEffort;
use App\Base\AI\Enums\ReasoningVisibility;
use App\Base\AI\Enums\ToolChoiceMode;

final class ProviderCapabilityRegistry
{
    public function capabilitiesFor(?string $providerName, string $model, AiApiType $apiType): ProviderExecutionCapabilities
    {
        if (in_array($apiType, [AiApiType::OpenAiResponses, AiApiType::OpenAiCodexResponses], true)) {
            return new ProviderExecutionCapabilities(
                supportedReasoningVisibility: [ReasoningVisibility::None, ReasoningVisibility::Summary],
                supportedReasoningEffort: [ReasoningEffort::Low, ReasoningEffort::Medium, ReasoningEffort::High],
                supportsReasoningBudget: true,
                supportsReasoningContextPreservation: true,
                agenticToolLoopReasoningVisibility: ReasoningVisibility::Summary,
                preserveReasoningContextInAgenticToolLoops: true,
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
                preserveReasoningContextInAgenticToolLoops: true,
            );
        }

        return new ProviderExecutionCapabilities;
    }

    private function isAnthropicProvider(?string $providerName): bool
    {
        return $providerName === 'anthropic';
    }

    private function supportsAnthropicAdaptiveThinking(string $model): bool
    {
        $id = str_contains($model, '/') ? basename($model) : $model;

        $supports = str_starts_with($id, 'claude-mythos');

        if (! $supports && preg_match('/^claude-(opus|sonnet)-(\d+)[.-](\d+)/', $id, $matches)) {
            $major = (int) $matches[2];
            $minor = (int) $matches[3];
            $supports = $major > 4 || ($major === 4 && $minor >= 6);
        }

        return $supports;
    }
}
