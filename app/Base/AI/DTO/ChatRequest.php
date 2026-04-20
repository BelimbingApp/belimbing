<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\DTO;

use App\Base\AI\Contracts\LlmTransportTap;
use App\Base\AI\Enums\AiApiType;
use InvalidArgumentException;

class ChatRequest
{
    public readonly ExecutionControls $executionControls;

    public function __construct(
        public readonly string $baseUrl,
        public readonly string $apiKey,
        public readonly string $model,
        public readonly array $messages,
        ?ExecutionControls $executionControls = null,
        public readonly int $timeout = 60,
        public readonly ?string $providerName = null,
        public readonly ?array $tools = null,
        public readonly AiApiType $apiType = AiApiType::OpenAiChatCompletions,
        public readonly ?LlmTransportTap $transportTap = null,
    ) {
        $this->executionControls = $executionControls ?? ExecutionControls::defaults();

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
