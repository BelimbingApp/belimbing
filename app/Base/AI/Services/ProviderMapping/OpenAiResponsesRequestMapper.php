<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\ReasoningVisibility;

final class OpenAiResponsesRequestMapper implements ProviderRequestMapper
{
    use OpenAiRequestMapperHelpers;

    public function __construct(
        private readonly ProviderCapabilityRegistry $capabilities,
    ) {}

    public function mapPayload(ChatRequest $request, bool $stream): array
    {
        [$instructions, $input] = $this->convertToResponsesInputWithInstructions($request->messages);
        $capabilities = $this->capabilities->capabilitiesFor($request->providerName, $request->model, $request->apiType);
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
        }

        if (
            $request->executionControls->reasoning->effort !== null
            && in_array($request->executionControls->reasoning->effort, $capabilities->supportedReasoningEffort, true)
        ) {
            $payload['reasoning']['effort'] = $request->executionControls->reasoning->effort->value;
        }

        if (
            $request->executionControls->reasoning->budget !== null
            && $capabilities->supportsReasoningBudget
        ) {
            $payload['reasoning']['budget_tokens'] = $request->executionControls->reasoning->budget;
        }

        return $payload;
    }
}
