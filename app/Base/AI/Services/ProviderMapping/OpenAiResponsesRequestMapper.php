<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderControlAdjustment;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\AI\Enums\ProviderControlAdjustmentType;
use App\Base\AI\Enums\ReasoningVisibility;

final class OpenAiResponsesRequestMapper implements ProviderRequestMapper
{
    use OpenAiRequestMapperHelpers;

    public function __construct(
        private readonly ProviderCapabilityRegistry $capabilities,
        private readonly ProviderRequestHeaderResolver $headers,
    ) {}

    public function mapPayload(ChatRequest $request, bool $stream): ProviderRequestMapping
    {
        [$instructions, $input] = $this->convertToResponsesInputWithInstructions($request->messages);
        $capabilities = $this->capabilities->capabilitiesFor($request->providerName, $request->model, $request->apiType);
        $adjustments = [];
        $payload = array_filter([
            'model' => $request->model,
            'instructions' => $instructions,
            'input' => $input,
            'max_output_tokens' => $request->executionControls->limits->maxOutputTokens,
            'stream' => $stream,
            'store' => false,
            'tools' => $request->tools !== null ? $this->convertToResponsesTools($request->tools) : null,
            'tool_choice' => $request->executionControls->tools->choice?->value,
        ], fn ($value) => $value !== null);

        if (
            in_array($request->executionControls->reasoning->visibility, $capabilities->supportedReasoningVisibility, true)
            && $request->executionControls->reasoning->visibility === ReasoningVisibility::Summary
        ) {
            $payload['reasoning'] = ['summary' => 'auto'];
        } elseif ($request->executionControls->reasoning->visibility === ReasoningVisibility::Full) {
            $adjustments[] = new ProviderControlAdjustment(
                ProviderControlAdjustmentType::Unsupported,
                'reasoning.visibility',
                $request->executionControls->reasoning->visibility->value,
                null,
                'OpenAI Responses supports summary visibility, not full reasoning visibility.',
            );
        }

        if (
            $request->executionControls->reasoning->effort !== null
            && in_array($request->executionControls->reasoning->effort, $capabilities->supportedReasoningEffort, true)
        ) {
            $payload['reasoning']['effort'] = $request->executionControls->reasoning->effort->value;
        } elseif ($request->executionControls->reasoning->effort !== null) {
            $adjustments[] = new ProviderControlAdjustment(
                ProviderControlAdjustmentType::Unsupported,
                'reasoning.effort',
                $request->executionControls->reasoning->effort->value,
                null,
                'OpenAI Responses does not support the requested reasoning effort for this model.',
            );
        }

        if (
            $request->executionControls->reasoning->budget !== null
            && $capabilities->supportsReasoningBudget
        ) {
            $payload['reasoning']['budget_tokens'] = $request->executionControls->reasoning->budget;
        } elseif ($request->executionControls->reasoning->budget !== null) {
            $adjustments[] = new ProviderControlAdjustment(
                ProviderControlAdjustmentType::Unsupported,
                'reasoning.budget',
                $request->executionControls->reasoning->budget,
                null,
                'OpenAI Responses does not support reasoning budgets for this model.',
            );
        }

        return new ProviderRequestMapping(
            payload: $payload,
            headers: $this->headers->headersFor($request),
            controlAdjustments: $adjustments,
        );
    }
}
