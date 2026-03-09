<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\Services\GithubCopilotAuthService;
use App\Base\AI\Services\LlmClient;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Modules\Core\AI\Contracts\DigitalWorkerTool;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\DigitalWorkerToolRegistry;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

class TestDigitalWorkerTool implements DigitalWorkerTool
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        private readonly string $toolName,
        private readonly string $toolDescription,
        private readonly array $schema,
        private readonly string $toolResult,
    ) {}

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        return $this->toolDescription;
    }

    public function parametersSchema(): array
    {
        return $this->schema;
    }

    public function requiredCapability(): ?string
    {
        return null;
    }

    public function execute(array $arguments): string
    {
        return $this->toolResult;
    }
}

function buildMockConfig(): array
{
    return [
        'api_key' => 'test-key',
        'base_url' => 'https://api.example.com/v1',
        'model' => 'gpt-4',
        'max_tokens' => 1024,
        'temperature' => 0.7,
        'timeout' => 30,
        'provider_name' => 'test-provider',
    ];
}

function buildTestMessage(string $content, string $role = 'user'): Message
{
    return new Message(
        role: $role,
        content: $content,
        timestamp: new \DateTimeImmutable,
    );
}

function buildAllowAllAuthzMock(): AuthorizationService
{
    $mock = Mockery::mock(AuthorizationService::class);
    $mock->shouldReceive('can')->andReturn(AuthorizationDecision::allow());

    return $mock;
}

function buildResolvedConfigResolverMock(): ConfigResolver
{
    $configResolver = Mockery::mock(ConfigResolver::class);
    $configResolver->shouldReceive('resolve')->andReturn([buildMockConfig()]);

    return $configResolver;
}

function buildToolRegistry(DigitalWorkerTool ...$tools): DigitalWorkerToolRegistry
{
    $registry = new DigitalWorkerToolRegistry(buildAllowAllAuthzMock());

    foreach ($tools as $tool) {
        $registry->register($tool);
    }

    return $registry;
}

function buildRuntime(
    LlmClient $llmClient,
    ?ConfigResolver $configResolver = null,
    ?DigitalWorkerToolRegistry $registry = null,
): AgenticRuntime {
    return new AgenticRuntime(
        $configResolver ?? buildResolvedConfigResolverMock(),
        $llmClient,
        Mockery::mock(GithubCopilotAuthService::class),
        $registry ?? buildToolRegistry(),
    );
}

function buildGenericTool(
    string $name,
    string $description,
    array $schema,
    string $result,
): DigitalWorkerTool
{
    return new TestDigitalWorkerTool($name, $description, $schema, $result);
}

function buildEchoTool(): DigitalWorkerTool
{
    return buildGenericTool(
        'echo_tool',
        'Echoes input',
        ['type' => 'object', 'properties' => ['input' => ['type' => 'string']]],
        'executed:echo_tool:world',
    );
}

function buildNavigateActionTool(): DigitalWorkerTool
{
    return buildGenericTool(
        'navigate_tool',
        'Returns Lara actions',
        ['type' => 'object'],
        '<lara-action>Livewire.navigate(\'/dashboard\')</lara-action>',
    );
}

function buildToolCallResponse(string $callId, string $toolName, string $arguments): array
{
    return [
        'content' => null,
        'latency_ms' => 200,
        'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 15],
        'tool_calls' => [
            [
                'id' => $callId,
                'type' => 'function',
                'function' => [
                    'name' => $toolName,
                    'arguments' => $arguments,
                ],
            ],
        ],
    ];
}

function buildFinalResponse(string $content): array
{
    return [
        'content' => $content,
        'latency_ms' => 150,
        'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 10],
    ];
}

describe('AgenticRuntime', function () {
    it('returns direct response when LLM produces no tool calls', function () {
        $configResolver = buildResolvedConfigResolverMock();

        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chat')->once()->andReturn([
            'content' => 'Hello, I am Lara!',
            'latency_ms' => 150,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 8],
        ]);

        $runtime = buildRuntime($llmClient, $configResolver);
        $result = $runtime->run([buildTestMessage('Hi')], 1, 'You are Lara.');

        expect($result['content'])->toBe('Hello, I am Lara!');
        expect($result['run_id'])->toStartWith('run_');
        expect($result['meta']['model'])->toBe('gpt-4');
        expect($result['meta']['provider_name'])->toBe('test-provider');
        expect($result['meta'])->not->toHaveKey('tool_actions');
    });

    it('executes tool calls and feeds results back to LLM', function () {
        $configResolver = buildResolvedConfigResolverMock();

        $llmClient = Mockery::mock(LlmClient::class);

        // First call: LLM wants to call a tool
        $llmClient->shouldReceive('chat')->once()->andReturn(
            buildToolCallResponse('call_001', 'echo_tool', '{"input": "world"}')
        );

        // Second call: LLM produces final response after receiving tool result
        $llmClient->shouldReceive('chat')->once()->andReturn(
            buildFinalResponse('The echo result was: executed:echo_tool:world')
        );

        $runtime = buildRuntime($llmClient, $configResolver, buildToolRegistry(buildEchoTool()));
        $result = $runtime->run([buildTestMessage('Echo world')], 1, 'You are Lara.');

        expect($result['content'])->toContain('executed:echo_tool:world');
        expect($result['meta']['tool_actions'])->toHaveCount(1);
        expect($result['meta']['tool_actions'][0]['tool'])->toBe('echo_tool');
        expect($result['meta']['tool_actions'][0]['arguments'])->toBe(['input' => 'world']);
    });

    it('prepends client actions collected from tool results to final content', function () {
        $configResolver = buildResolvedConfigResolverMock();

        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chat')->once()->andReturn(
            buildToolCallResponse('call_002', 'navigate_tool', '{}')
        );
        $llmClient->shouldReceive('chat')->once()->andReturn(
            buildFinalResponse('Navigated successfully.')
        );

        $runtime = buildRuntime($llmClient, $configResolver, buildToolRegistry(buildNavigateActionTool()));
        $result = $runtime->run([buildTestMessage('Go to dashboard')], 1, 'You are Lara.');

        expect($result['content'])->toStartWith('<lara-action>Livewire.navigate(\'/dashboard\')</lara-action>')
            ->and($result['content'])->toContain('Navigated successfully.');
    });

    it('returns error when no LLM configuration is available', function () {
        // Stub resolveConfig to return null by making resolve return empty
        // and having no employee in DB. We mock at the config resolver level.
        $configResolver = Mockery::mock(ConfigResolver::class);
        $configResolver->shouldReceive('resolve')->with(1)->andReturn([]);
        $configResolver->shouldReceive('resolveDefault')->andReturn(null);

        $llmClient = Mockery::mock(LlmClient::class);
        $runtime = buildRuntime($llmClient, $configResolver);

        // Employee ID 1 doesn't exist in test DB, so company lookup fails gracefully
        $result = $runtime->run([buildTestMessage('Hello')], 1, 'Prompt');

        expect($result['content'])->toContain('⚠');
        expect($result['meta'])->toHaveKey('error');
    });

    it('returns error when LLM call fails', function () {
        $configResolver = buildResolvedConfigResolverMock();

        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chat')->once()->andReturn([
            'error' => 'Rate limit exceeded',
            'error_type' => 'rate_limit',
            'latency_ms' => 50,
        ]);

        $runtime = buildRuntime($llmClient, $configResolver);
        $result = $runtime->run([buildTestMessage('Hello')], 1, 'Prompt');

        expect($result['content'])->toContain('⚠');
        expect($result['meta']['error_type'])->toBe('rate_limit');
    });
});
