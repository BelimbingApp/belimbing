<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Services\Protocols;

use App\Base\AI\Enums\AiApiType;

final class LlmProtocolClientRegistry
{
    public function __construct(
        private readonly ChatCompletionsProtocolClient $chatCompletions,
        private readonly ResponsesProtocolClient $responses,
        private readonly AnthropicMessagesProtocolClient $anthropicMessages,
    ) {}

    public function forApiType(AiApiType $apiType): LlmProtocolClient
    {
        return match ($apiType) {
            AiApiType::OpenAiResponses => $this->responses,
            AiApiType::AnthropicMessages => $this->anthropicMessages,
            default => $this->chatCompletions,
        };
    }
}
