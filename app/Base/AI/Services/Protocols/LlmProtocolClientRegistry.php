<?php
namespace App\Base\AI\Services\Protocols;

use App\Base\AI\Enums\AiApiType;

final class LlmProtocolClientRegistry
{
    public function __construct(
        private readonly ChatCompletionsProtocolClient $chatCompletions,
        private readonly ResponsesProtocolClient $responses,
        private readonly OpenAiCodexResponsesProtocolClient $codexResponses,
        private readonly AnthropicMessagesProtocolClient $anthropicMessages,
    ) {}

    public function forApiType(AiApiType $apiType): LlmProtocolClient
    {
        return match ($apiType) {
            AiApiType::OpenAiResponses => $this->responses,
            AiApiType::OpenAiCodexResponses => $this->codexResponses,
            AiApiType::AnthropicMessages => $this->anthropicMessages,
            default => $this->chatCompletions,
        };
    }
}
