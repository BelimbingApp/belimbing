<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\Contracts\Tool;
use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Services\LlmClient;
use App\Base\AI\Tools\ToolResult;
use App\Modules\Core\AI\DTO\ExecutionPolicy;
use App\Modules\Core\AI\Enums\ExecutionMode;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Services\AgentToolRegistry;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\RuntimeSessionContext;
use Illuminate\Foundation\Testing\TestCase;
use Tests\Support\MakesRuntimeResponses;

uses(TestCase::class, MakesRuntimeResponses::class);

const AGENTIC_RUNTIME_SYSTEM_PROMPT = 'You are Lara.';
const AGENTIC_RUNTIME_TOO_MANY_REQUESTS = 'HTTP 429: Too Many Requests';

class TestTool implements Tool
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

    public function category(): ToolCategory
    {
        return ToolCategory::SYSTEM;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::READ_ONLY;
    }

    public function displayName(): string
    {
        return $this->toolName;
    }

    public function summary(): string
    {
        return $this->toolDescription;
    }

    public function explanation(): string
    {
        return '';
    }

    public function setupRequirements(): array
    {
        return [];
    }

    public function testExamples(): array
    {
        return [];
    }

    public function healthChecks(): array
    {
        return [];
    }

    public function limits(): array
    {
        return [];
    }

    public function execute(array $arguments): ToolResult
    {
        if (empty($arguments)) {
            return ToolResult::success($this->toolResult);
        }

        return ToolResult::success($this->toolResult.json_encode($arguments));
    }
}

function buildGenericTool(
    string $name,
    string $description,
    array $schema,
    string $result,
): Tool {
    return new TestTool($name, $description, $schema, $result);
}

function buildEchoTool(): Tool
{
    return buildGenericTool(
        'echo_tool',
        'Echoes input',
        ['type' => 'object', 'properties' => ['input' => ['type' => 'string']]],
        'executed:echo_tool:world',
    );
}

function buildNavigateActionTool(): Tool
{
    return buildGenericTool(
        'navigate_tool',
        'Returns agent actions',
        ['type' => 'object'],
        '<agent-action>Livewire.navigate(\'/dashboard\')</agent-action>',
    );
}

function buildSessionEchoTool(): Tool
{
    return new class implements Tool
    {
        public function name(): string
        {
            return 'session_echo_tool';
        }

        public function description(): string
        {
            return 'Returns the active runtime session ID.';
        }

        public function parametersSchema(): array
        {
            return ['type' => 'object', 'properties' => []];
        }

        public function requiredCapability(): ?string
        {
            return null;
        }

        public function category(): ToolCategory
        {
            return ToolCategory::SYSTEM;
        }

        public function riskClass(): ToolRiskClass
        {
            return ToolRiskClass::READ_ONLY;
        }

        public function displayName(): string
        {
            return 'session_echo_tool';
        }

        public function summary(): string
        {
            return 'Echo runtime session';
        }

        public function explanation(): string
        {
            return '';
        }

        public function setupRequirements(): array
        {
            return [];
        }

        public function testExamples(): array
        {
            return [];
        }

        public function healthChecks(): array
        {
            return [];
        }

        public function limits(): array
        {
            return [];
        }

        public function execute(array $arguments): ToolResult
        {
            return ToolResult::success(app(RuntimeSessionContext::class)->sessionId() ?? 'no-session');
        }
    };
}

function defaultAgenticConfigResolver(): ConfigResolver
{
    return test()->mockResolvedConfigResolver([
        test()->makeConfig('test-provider', 'gpt-4', 'test-key'),
    ]);
}

function runAgenticConversation(
    LlmClient $llmClient,
    ?ConfigResolver $configResolver = null,
    ?AgentToolRegistry $toolRegistry = null,
    string $userMessage = 'Hello',
    string $systemPrompt = 'Prompt',
    ?ExecutionPolicy $policy = null,
    ?array $allowedToolNames = null,
): array {
    return test()
        ->makeAgenticRuntime($llmClient, $configResolver ?? defaultAgenticConfigResolver(), $toolRegistry)
        ->run([test()->makeMessage('user', $userMessage)], 1, $systemPrompt, null, $policy, null, null, $allowedToolNames);
}

describe('AgenticRuntime (sync)', function () {
    it('returns direct response when LLM produces no tool calls', function () {
        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chat')->once()->andReturn([
            'content' => 'Hello, I am Lara!',
            'latency_ms' => 150,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 8],
        ]);

        $result = runAgenticConversation($llmClient, userMessage: 'Hi', systemPrompt: AGENTIC_RUNTIME_SYSTEM_PROMPT);

        expect($result['content'])->toBe('Hello, I am Lara!');
        expect($result['run_id'])->toStartWith('run_');
        expect($result['meta']['model'])->toBe('gpt-4');
        expect($result['meta']['provider_name'])->toBe('test-provider');
        expect($result['meta'])->not->toHaveKey('tool_actions');
    });

    it('omits disallowed tools from the LLM request', function () {
        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chat')
            ->once()
            ->withArgs(function ($request): bool {
                return $request->tools === null;
            })
            ->andReturn([
                'content' => 'Coding profile response',
                'latency_ms' => 120,
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 8],
            ]);

        $result = runAgenticConversation(
            $llmClient,
            toolRegistry: $this->makeToolRegistry(buildEchoTool()),
            allowedToolNames: [],
        );

        expect($result['content'])->toBe('Coding profile response');
    });

    it('executes tool calls and feeds results back to LLM', function () {
        $llmClient = Mockery::mock(LlmClient::class);

        // First call: LLM wants to call a tool
        $llmClient->shouldReceive('chat')->once()->andReturn(
            $this->makeToolCallResponse('call_001', 'echo_tool', '{"input": "world"}')
        );

        // Second call: LLM produces final response after receiving tool result
        $llmClient->shouldReceive('chat')->once()->andReturn(
            $this->makeFinalResponse('The echo result was: executed:echo_tool:world')
        );

        $result = runAgenticConversation(
            $llmClient,
            toolRegistry: $this->makeToolRegistry(buildEchoTool()),
            userMessage: 'Echo world',
            systemPrompt: AGENTIC_RUNTIME_SYSTEM_PROMPT,
        );

        expect($result['content'])->toContain('executed:echo_tool:world');
        expect($result['meta']['tool_actions'])->toHaveCount(1);
        expect($result['meta']['tool_actions'][0]['tool'])->toBe('echo_tool');
        expect($result['meta']['tool_actions'][0]['arguments'])->toBe(['input' => 'world']);
    });

    it('preserves assistant reasoning_content across tool loop iterations', function () {
        $llmClient = Mockery::mock(LlmClient::class);

        $llmClient->shouldReceive('chat')->once()->andReturn([
            'content' => null,
            'reasoning_content' => 'Need the tool result before answering.',
            'latency_ms' => 200,
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 15],
            'tool_calls' => [
                [
                    'id' => 'call_reasoning_001',
                    'type' => 'function',
                    'function' => [
                        'name' => 'echo_tool',
                        'arguments' => '{"input":"world"}',
                    ],
                ],
            ],
        ]);

        $llmClient->shouldReceive('chat')
            ->once()
            ->withArgs(function ($request): bool {
                $assistantMessages = array_values(array_filter(
                    $request->messages,
                    static fn (array $message): bool => ($message['role'] ?? null) === 'assistant'
                ));

                if ($assistantMessages === []) {
                    return false;
                }

                $assistantMessage = $assistantMessages[0];

                return ($assistantMessage['reasoning_content'] ?? null) === 'Need the tool result before answering.'
                    && ($assistantMessage['tool_calls'][0]['id'] ?? null) === 'call_reasoning_001';
            })
            ->andReturn($this->makeFinalResponse('The echo result was: executed:echo_tool:world'));

        $result = runAgenticConversation(
            $llmClient,
            toolRegistry: $this->makeToolRegistry(buildEchoTool()),
            userMessage: 'Echo world',
            systemPrompt: AGENTIC_RUNTIME_SYSTEM_PROMPT,
        );

        expect($result['content'])->toContain('executed:echo_tool:world');
    });

    it('exposes the active chat session to tool execution and clears it afterwards', function () {
        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chat')->once()->andReturn(
            $this->makeToolCallResponse('call_001', 'session_echo_tool', '{}')
        );
        $llmClient->shouldReceive('chat')->once()->andReturn(
            $this->makeFinalResponse('Session: sess_tool_123')
        );

        $runtime = $this->makeAgenticRuntime(
            $llmClient,
            toolRegistry: $this->makeToolRegistry(buildSessionEchoTool()),
        );

        $result = $runtime->run(
            [test()->makeMessage('user', 'Delegate this task')],
            1,
            AGENTIC_RUNTIME_SYSTEM_PROMPT,
            null,
            null,
            'sess_tool_123',
        );

        expect($result['content'])->toContain('sess_tool_123')
            ->and(app(RuntimeSessionContext::class)->sessionId())->toBeNull();
    });

    it('prepends client actions collected from tool results to final content', function () {
        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chat')->once()->andReturn(
            $this->makeToolCallResponse('call_002', 'navigate_tool', '{}')
        );
        $llmClient->shouldReceive('chat')->once()->andReturn(
            $this->makeFinalResponse('Navigated successfully.')
        );

        $result = runAgenticConversation(
            $llmClient,
            toolRegistry: $this->makeToolRegistry(buildNavigateActionTool()),
            userMessage: 'Go to dashboard',
            systemPrompt: AGENTIC_RUNTIME_SYSTEM_PROMPT,
        );

        expect($result['content'])->toStartWith('<agent-action>Livewire.navigate(\'/dashboard\')</agent-action>')
            ->and($result['content'])->toContain('Navigated successfully.');
    });

    it('returns error when no LLM configuration is available', function () {
        $configResolver = Mockery::mock(ConfigResolver::class);
        $configResolver->shouldReceive('resolveWithDefaultFallback')->with(1)->andReturn([]);

        $llmClient = Mockery::mock(LlmClient::class);
        $result = runAgenticConversation($llmClient, $configResolver);

        expect($result['content'])->toContain('⚠');
        expect($result['meta'])->toHaveKey('error');
    });

    it('returns error when LLM call fails with non-retryable error', function () {
        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chat')->once()->andReturn(
            $this->makeErrorResponse(AiErrorType::AuthError, 'Invalid API key', 50)
        );

        $result = runAgenticConversation($llmClient);

        expect($result['content'])->toContain('⚠');
        expect($result['meta']['error_type'])->toBe('auth_error');
        expect($result['meta']['retry_attempts'])->toBe([]);
    });

    it('retries once on retryable error then returns error if retry also fails', function () {
        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chat')->twice()->andReturn(
            $this->makeErrorResponse(AiErrorType::RateLimit, 'Rate limit exceeded', 50)
        );

        $result = runAgenticConversation($llmClient);

        expect($result['content'])->toContain('⚠');
        expect($result['meta']['error_type'])->toBe('rate_limit');
        expect($result['meta']['retry_attempts'])->toHaveCount(1);
        expect($result['meta']['retry_attempts'][0]['error_type'])->toBe('rate_limit');
    });

    it('retries once on retryable error then succeeds if retry works', function () {
        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chat')->once()->andReturn(
            $this->makeErrorResponse(AiErrorType::Timeout, 'Connection timed out', 5000)
        );
        $llmClient->shouldReceive('chat')->once()->andReturn(
            $this->makeFinalResponse('Success after retry!')
        );

        $result = runAgenticConversation($llmClient);

        expect($result['content'])->toBe('Success after retry!');
        expect($result['meta']['retry_attempts'])->toHaveCount(1);
        expect($result['meta']['retry_attempts'][0]['error_type'])->toBe('timeout');
    });

    it('does not retry timeout when full budget was consumed', function () {
        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chat')->once()->andReturn(
            $this->makeErrorResponse(AiErrorType::Timeout, 'Request timed out', 55000)
        );

        $result = runAgenticConversation(
            $llmClient,
            policy: new ExecutionPolicy(ExecutionMode::Interactive, 60),
        );

        expect($result['meta']['error_type'])->toBe('timeout');
        expect($result['meta']['retry_attempts'])->toBeEmpty();
    });

    it('falls back to backup model on retryable runtime error in sync mode', function () {
        $llmClient = Mockery::mock(LlmClient::class);
        // Primary fails with rate limit on BOTH attempts (initial + retry)
        $llmClient->shouldReceive('chat')->twice()->andReturn(
            $this->makeErrorResponse(AiErrorType::RateLimit, AGENTIC_RUNTIME_TOO_MANY_REQUESTS, 50)
        );
        // Backup succeeds
        $llmClient->shouldReceive('chat')->once()->andReturn(
            $this->makeFinalResponse('Success from backup model!')
        );

        $configResolver = $this->mockResolvedConfigResolver([
            $this->makeConfig('primary-provider', 'gpt-4-primary'),
            $this->makeConfig('backup-provider', 'gpt-4-backup'),
        ]);

        $result = runAgenticConversation($llmClient, $configResolver);

        expect($result['content'])->toBe('Success from backup model!')
            ->and($result['meta']['model'])->toBe('gpt-4-backup')
            ->and($result['meta']['fallback_attempts'])->toHaveCount(1)
            ->and($result['meta']['fallback_attempts'][0]['provider'])->toBe('primary-provider')
            ->and($result['meta']['fallback_attempts'][0]['error_type'])->toBe('rate_limit');
    });
});

describe('AgenticRuntime (streaming)', function () {
    it('falls back to backup model on retryable runtime error in stream mode', function () {
        $llmClient = Mockery::mock(LlmClient::class);
        // Primary fails with rate limit - streaming error
        $llmClient->shouldReceive('chatStream')->once()->andReturnUsing(function () {
            yield ['type' => 'error', 'runtime_error' => AiRuntimeError::fromType(
                AiErrorType::RateLimit,
                AGENTIC_RUNTIME_TOO_MANY_REQUESTS,
                latencyMs: 50
            ), 'latency_ms' => 50];
        });
        // Backup succeeds
        $llmClient->shouldReceive('chatStream')->once()->andReturnUsing(function () {
            yield ['type' => 'content_delta', 'text' => 'Success from backup!'];
            yield ['type' => 'done', 'finish_reason' => 'stop', 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5], 'latency_ms' => 200];
        });

        $configResolver = $this->mockResolvedConfigResolver([
            $this->makeConfig('primary-provider', 'gpt-4-primary'),
            $this->makeConfig('backup-provider', 'gpt-4-backup'),
        ]);

        $runtime = $this->makeAgenticRuntime($llmClient, $configResolver);
        $events = iterator_to_array($runtime->runStream(
            [test()->makeMessage('user', 'Hello')],
            1,
            AGENTIC_RUNTIME_SYSTEM_PROMPT,
        ));

        // Find the final done event
        $doneEvent = null;
        $errorEvents = [];
        foreach ($events as $event) {
            if ($event['event'] === 'done') {
                $doneEvent = $event;
            }
            if ($event['event'] === 'error') {
                $errorEvents[] = $event;
            }
        }

        expect($doneEvent)->not->toBeNull()
            ->and($doneEvent['data']['content'] ?? '')->toBe('Success from backup!')
            ->and($doneEvent['data']['meta']['model'] ?? '')->toBe('gpt-4-backup')
            ->and($doneEvent['data']['meta']['fallback_attempts'] ?? [])->toHaveCount(1)
            ->and($doneEvent['data']['meta']['fallback_attempts'][0]['provider'] ?? '')->toBe('primary-provider')
            ->and($doneEvent['data']['meta']['fallback_attempts'][0]['error_type'] ?? '')->toBe('rate_limit');
    });

    it('yields stream events before the full provider stream completes', function () {
        $allowCompletion = false;

        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chatStream')->once()->andReturnUsing(function () use (&$allowCompletion) {
            return (function () use (&$allowCompletion) {
                yield ['type' => 'thinking_delta', 'text' => 'Inspecting...'];

                if (! $allowCompletion) {
                    throw new RuntimeException(
                        'Stream advanced before caller consumed the first chunk',
                    );
                }

                yield ['type' => 'content_delta', 'text' => 'Hi'];
                yield [
                    'type' => 'done',
                    'finish_reason' => 'stop',
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                    'latency_ms' => 200,
                ];
            })();
        });

        $runtime = $this->makeAgenticRuntime($llmClient);
        $stream = $runtime->runStream(
            [test()->makeMessage('user', 'Hello')],
            1,
            AGENTIC_RUNTIME_SYSTEM_PROMPT,
        );

        $firstEvent = $stream->current();

        expect($firstEvent['event'])->toBe('status')
            ->and($firstEvent['data']['phase'])->toBe(TurnPhase::AwaitingLlm->value)
            ->and($firstEvent['data']['iteration'])->toBe(0)
            ->and($firstEvent['data']['run_id'])->toStartWith('run_');

        $stream->next();
        $secondEvent = $stream->current();

        expect($secondEvent['event'])->toBe('status')
            ->and($secondEvent['data']['phase'])->toBe('thinking_delta')
            ->and($secondEvent['data']['delta'])->toBe('Inspecting...');

        $allowCompletion = true;

        $stream->next();
        $thirdEvent = $stream->current();

        expect($thirdEvent['event'])->toBe('delta')
            ->and($thirdEvent['data']['text'])->toBe('Hi');

        $stream->next();
        $doneEvent = $stream->current();

        expect($doneEvent['event'])->toBe('done')
            ->and($doneEvent['data']['meta']['latency_ms'])->toBe(200);
    });

    it('does not fall back in stream mode after the provider already emitted output', function () {
        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chatStream')->once()->andReturnUsing(function () {
            yield ['type' => 'thinking_delta', 'text' => 'Inspecting...'];
            yield ['type' => 'error', 'runtime_error' => AiRuntimeError::fromType(
                AiErrorType::RateLimit,
                AGENTIC_RUNTIME_TOO_MANY_REQUESTS,
                latencyMs: 50
            ), 'latency_ms' => 50];
        });

        $configResolver = $this->mockResolvedConfigResolver([
            $this->makeConfig('primary-provider', 'gpt-4-primary'),
            $this->makeConfig('backup-provider', 'gpt-4-backup'),
        ]);

        $runtime = $this->makeAgenticRuntime($llmClient, $configResolver);
        $events = iterator_to_array($runtime->runStream(
            [test()->makeMessage('user', 'Hello')],
            1,
            AGENTIC_RUNTIME_SYSTEM_PROMPT,
        ));

        expect(collect($events)->pluck('event')->all())->toContain('error')
            ->and(collect($events)->pluck('event')->all())->not->toContain('done');

        $recoveryEvents = array_values(array_filter(
            $events,
            static fn (array $event): bool => $event['event'] === 'status'
                && ($event['data']['phase'] ?? null) === 'recovery_attempted'
        ));

        expect($recoveryEvents)->toBeEmpty();

        $errorEvent = collect($events)->firstWhere('event', 'error');

        expect($errorEvent['data']['meta']['model'] ?? null)->toBe('gpt-4-primary')
            ->and($errorEvent['data']['meta']['fallback_attempts'] ?? [])->toBeEmpty();
    });

    it('does not fall back on non-retryable runtime error in sync mode', function () {
        $llmClient = Mockery::mock(LlmClient::class);
        // Primary fails with auth error (non-retryable)
        $llmClient->shouldReceive('chat')->once()->andReturn(
            $this->makeErrorResponse(AiErrorType::AuthError, 'Invalid API key', 50)
        );
        // Backup should NOT be called

        $configResolver = $this->mockResolvedConfigResolver([
            $this->makeConfig('primary-provider', 'gpt-4-primary'),
            $this->makeConfig('backup-provider', 'gpt-4-backup'),
        ]);

        $result = runAgenticConversation($llmClient, $configResolver);

        expect($result['meta']['error_type'])->toBe('auth_error')
            ->and($result['meta']['fallback_attempts'])->toBeEmpty();
    });

    it('surfaces the last stream runtime error when every configuration hits a retryable stream failure', function () {
        $rateLimit = AiRuntimeError::fromType(
            AiErrorType::RateLimit,
            AGENTIC_RUNTIME_TOO_MANY_REQUESTS,
            latencyMs: 50,
        );

        $llmClient = Mockery::mock(LlmClient::class);
        $llmClient->shouldReceive('chatStream')->twice()->andReturnUsing(function () use ($rateLimit) {
            yield ['type' => 'error', 'runtime_error' => $rateLimit, 'latency_ms' => 50];
        });

        $configResolver = $this->mockResolvedConfigResolver([
            $this->makeConfig('primary-provider', 'gpt-4-primary'),
            $this->makeConfig('backup-provider', 'gpt-4-backup'),
        ]);

        $runtime = $this->makeAgenticRuntime($llmClient, $configResolver);
        $events = iterator_to_array($runtime->runStream(
            [test()->makeMessage('user', 'Hello')],
            1,
            AGENTIC_RUNTIME_SYSTEM_PROMPT,
        ));

        $terminal = collect($events)->last(static fn (array $e): bool => $e['event'] === 'error');

        expect($terminal)->not->toBeNull()
            ->and($terminal['data']['meta']['error_type'] ?? null)->toBe('rate_limit')
            ->and($terminal['data']['meta']['fallback_attempts'] ?? [])->toHaveCount(2)
            ->and($terminal['data']['meta']['fallback_attempts'][0]['error_type'] ?? null)->toBe('rate_limit')
            ->and($terminal['data']['meta']['fallback_attempts'][1]['error_type'] ?? null)->toBe('rate_limit');
    });

    it('applies model override only to the primary slot so fallback uses the workspace backup model', function () {
        $primary = $this->makeConfig('github-copilot', 'gpt-primary-slot');
        $backup = $this->makeConfig('moonshotai', 'moonshot-v1-auto', 'moon-key', 'https://api.moonshot.example/v1');
        $configResolver = $this->mockResolvedConfigResolver([$primary, $backup]);

        $llmClient = Mockery::mock(LlmClient::class);
        $seenModels = [];
        $llmClient->shouldReceive('chat')->times(3)->andReturnUsing(function (ChatRequest $request) use (&$seenModels): array {
            $seenModels[] = [$request->model, $request->baseUrl];

            if (count($seenModels) <= 2) {
                return $this->makeErrorResponse(AiErrorType::RateLimit, 'HTTP 429', 50);
            }

            return $this->makeFinalResponse('From backup');
        });

        $runtime = $this->makeAgenticRuntime($llmClient, $configResolver);
        $result = $runtime->run(
            [test()->makeMessage('user', 'Hi')],
            1,
            AGENTIC_RUNTIME_SYSTEM_PROMPT,
            'claude-opus-4.6',
        );

        expect($result['content'])->toContain('From backup')
            ->and($seenModels)->toHaveCount(3)
            ->and($seenModels[0][0])->toBe('claude-opus-4.6')
            ->and($seenModels[0][1])->toBe($primary['base_url'])
            ->and($seenModels[1][0])->toBe('claude-opus-4.6')
            ->and($seenModels[1][1])->toBe($primary['base_url'])
            ->and($seenModels[2][0])->toBe('moonshot-v1-auto')
            ->and($seenModels[2][1])->toBe($backup['base_url']);
    });
});
