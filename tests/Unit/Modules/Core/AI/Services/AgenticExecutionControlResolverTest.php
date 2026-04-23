<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\ReasoningVisibility;
use App\Base\AI\Enums\ToolChoiceMode;
use App\Modules\Core\AI\Services\AgenticExecutionControlResolver;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

it('applies responses tool-loop defaults from provider capabilities', function (): void {
    $resolver = app(AgenticExecutionControlResolver::class);

    $resolved = $resolver->resolve(
        ExecutionControls::defaults(maxOutputTokens: 512, temperature: 0.4),
        'openai',
        'gpt-5.4',
        AiApiType::OpenAiResponses,
        true,
    );

    expect($resolved->tools->choice)->toBe(ToolChoiceMode::Auto)
        ->and($resolved->reasoning->visibility)->toBe(ReasoningVisibility::Summary)
        ->and($resolved->tools->preserveReasoningContext)->toBeTrue();
});

it('preserves anthropic reasoning continuity without forcing summary visibility', function (): void {
    $resolver = app(AgenticExecutionControlResolver::class);

    $resolved = $resolver->resolve(
        ExecutionControls::defaults(maxOutputTokens: 512, temperature: 0.4),
        'anthropic',
        'claude-sonnet-4-6',
        AiApiType::AnthropicMessages,
        false,
    );

    expect($resolved->tools->choice)->toBeNull()
        ->and($resolved->reasoning->visibility)->toBe(ReasoningVisibility::None)
        ->and($resolved->tools->preserveReasoningContext)->toBeTrue();
});
