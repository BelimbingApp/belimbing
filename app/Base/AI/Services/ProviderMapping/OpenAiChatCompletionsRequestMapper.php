<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderRequestMapping;
use App\Base\AI\Enums\ReasoningMode;

final class OpenAiChatCompletionsRequestMapper implements ProviderRequestMapper
{
    use OpenAiRequestMapperHelpers;

    public function __construct(
        private readonly ProviderRequestHeaderResolver $headers,
    ) {}

    public function mapPayload(ChatRequest $request, bool $stream): ProviderRequestMapping
    {
        $payload = [
            'model' => $request->model,
            'messages' => $request->messages,
            'max_tokens' => $request->executionControls->limits->maxOutputTokens,
            'stream' => $stream ? true : null,
            'stream_options' => $stream && $request->providerName === 'openai'
                ? ['include_usage' => true]
                : null,
            'tools' => $this->normalizeTools($request->tools),
            'tool_choice' => $request->executionControls->tools->choice?->value,
        ];

        if ($request->executionControls->reasoning->mode === ReasoningMode::Disabled) {
            $payload['thinking'] = ['type' => 'disabled'];
        }

        return new ProviderRequestMapping(
            payload: array_filter($payload, fn ($value) => $value !== null),
            headers: $this->headers->headersFor($request),
            controlAdjustments: [],
        );
    }
}
