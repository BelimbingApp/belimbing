<?php
namespace App\Base\AI\Services\ProviderMapping;

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ProviderRequestMapping;

interface ProviderRequestMapper
{
    public function mapPayload(ChatRequest $request, bool $stream): ProviderRequestMapping;
}
