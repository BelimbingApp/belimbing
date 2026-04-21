<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\Enums\AiApiType;

final class ProviderRequestMapperRegistry
{
    public function __construct(
        private readonly ProviderCapabilityRegistry $capabilities,
        private readonly ProviderRequestHeaderResolver $headers,
    ) {}

    public function forApiType(AiApiType $apiType): ProviderRequestMapper
    {
        return match ($apiType) {
            AiApiType::OpenAiResponses => new OpenAiResponsesRequestMapper($this->capabilities, $this->headers),
            AiApiType::OpenAiCodexResponses => new OpenAiResponsesRequestMapper($this->capabilities, $this->headers),
            AiApiType::AnthropicMessages => new AnthropicMessagesRequestMapper($this->capabilities),
            default => new OpenAiChatCompletionsRequestMapper($this->capabilities, $this->headers),
        };
    }
}
