<?php
namespace App\Base\AI\Services\Protocols;

use App\Base\AI\DTO\ChatRequest;
use Generator;

interface LlmProtocolClient
{
    public function chat(ChatRequest $request): array;

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function chatStream(ChatRequest $request): Generator;
}
