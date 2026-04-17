<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\ReasoningMode;

final class OpenAiChatCompletionsRequestMapper implements ProviderRequestMapper
{
    use OpenAiRequestMapperHelpers;

    public function __construct(
        private readonly ProviderCapabilityRegistry $capabilities,
    ) {}

    public function mapPayload(ChatRequest $request, bool $stream): array
    {
        $capabilities = $this->capabilities->capabilitiesFor($request->providerName, $request->model, $request->apiType);
        $payload = [
            'model' => $request->model,
            'messages' => $request->messages,
            'max_tokens' => $request->executionControls->limits->maxOutputTokens,
            'temperature' => $request->executionControls->sampling->temperature,
            'top_p' => $request->executionControls->sampling->topP,
            'n' => $request->executionControls->sampling->candidateCount,
            'presence_penalty' => $request->executionControls->sampling->presencePenalty,
            'frequency_penalty' => $request->executionControls->sampling->frequencyPenalty,
            'stream' => $stream ? true : null,
            'tools' => $this->normalizeTools($request->tools, $capabilities),
            'tool_choice' => $request->executionControls->tools->choice?->value,
        ];

        if ($request->executionControls->reasoning->mode === ReasoningMode::Disabled) {
            $payload['thinking'] = ['type' => 'disabled'];
        }

        return array_filter(
            $this->applyFixedSampling($payload, $request, $capabilities),
            fn ($value) => $value !== null
        );
    }
}
