<?php

use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Services\ModelCatalogService;
use Tests\TestCase;

uses(TestCase::class);

it('resolves Anthropic models to the native Messages API type', function (): void {
    $service = new ModelCatalogService;

    expect($service->resolveApiType('anthropic', 'claude-sonnet-4-6'))
        ->toBe(AiApiType::AnthropicMessages);
});
