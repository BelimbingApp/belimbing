<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\DTO;

use App\Base\AI\Enums\AiApiType;
use InvalidArgumentException;

class ChatRequest
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $apiKey,
        public readonly string $model,
        public readonly array $messages,
        public readonly int $maxTokens = 2048,
        public readonly ?float $temperature = 0.7,
        public readonly int $timeout = 60,
        public readonly ?string $providerName = null,
        public readonly ?array $tools = null,
        public readonly ?string $toolChoice = null,
        public readonly AiApiType $apiType = AiApiType::OpenAiChatCompletions,
        public readonly ?string $reasoningSummary = null,
    ) {
        if ($this->baseUrl === '') {
            throw new InvalidArgumentException('baseUrl is required');
        }
        if ($this->model === '') {
            throw new InvalidArgumentException('model is required');
        }
        if ($this->messages === []) {
            throw new InvalidArgumentException('messages is required');
        }
    }
}
