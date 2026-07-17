<?php

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
                supportedReasoningEffort: $this->openAiReasoningEfforts($model, $apiType),
                supportsReasoningBudget: true,
                supportsReasoningContextPreservation: true,
                agenticToolLoopReasoningVisibility: ReasoningVisibility::Summary,
                preserveReasoningContextInAgenticToolLoops: true,
            );
        }

        if ($apiType === AiApiType::OpenAiChatCompletions) {
            return $this->kimiCapabilities($model);
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

    /**
     * OpenAI effort controls are model-specific. Unknown models expose no
     * selector rather than offering values that may be rejected at the wire.
     *
     * @return list<ReasoningEffort>
     */
    private function openAiReasoningEfforts(string $model, AiApiType $apiType): array
    {
        $id = strtolower(str_contains($model, '/') ? basename($model) : $model);

        if ($apiType === AiApiType::OpenAiResponses && str_starts_with($id, 'gpt-5.6')) {
            return [
                ReasoningEffort::None,
                ReasoningEffort::Low,
                ReasoningEffort::Medium,
                ReasoningEffort::High,
                ReasoningEffort::XHigh,
                ReasoningEffort::Max,
            ];
        }

        if ($apiType !== AiApiType::OpenAiCodexResponses) {
            return [];
        }

        if (str_starts_with($id, 'gpt-5.6-sol') || str_starts_with($id, 'gpt-5.6-terra')) {
            return [
                ReasoningEffort::Low,
                ReasoningEffort::Medium,
                ReasoningEffort::High,
                ReasoningEffort::XHigh,
                ReasoningEffort::Max,
                ReasoningEffort::Ultra,
            ];
        }

        if (str_starts_with($id, 'gpt-5.6-luna')) {
            return [
                ReasoningEffort::Low,
                ReasoningEffort::Medium,
                ReasoningEffort::High,
                ReasoningEffort::XHigh,
                ReasoningEffort::Max,
            ];
        }

        if (str_starts_with($id, 'gpt-5.4')) {
            return [
                ReasoningEffort::Low,
                ReasoningEffort::Medium,
                ReasoningEffort::High,
                ReasoningEffort::XHigh,
            ];
        }

        return [];
    }

    /**
     * Kimi thinking models expose reasoning controls over Chat Completions.
     *
     * K3 dials intensity via `reasoning_effort` (only "max" today) and cannot
     * be toggled off; K2.5/K2.6 toggle via the `thinking` object, with K2.6
     * adding preserved reasoning (`keep: "all"`). Always-thinking models
     * (kimi-k2.7*, kimi-k2-thinking) take no request controls at all.
     */
    private function kimiCapabilities(string $model): ProviderExecutionCapabilities
    {
        return match (KimiModelFamily::fromModel($model)) {
            KimiModelFamily::K3 => new ProviderExecutionCapabilities(
                supportedReasoningEffort: [ReasoningEffort::Max],
                supportsNativeReasoningBlocks: true,
                supportsReasoningModeToggle: false,
            ),
            KimiModelFamily::K2Thinking => new ProviderExecutionCapabilities(
                supportsNativeReasoningBlocks: true,
            ),
            KimiModelFamily::K2ThinkingKeep => new ProviderExecutionCapabilities(
                supportsReasoningContextPreservation: true,
                supportsNativeReasoningBlocks: true,
            ),
            default => new ProviderExecutionCapabilities,
        };
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
