<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Enums\ReasoningMode;
use App\Base\AI\Enums\ReasoningVisibility;
use App\Base\AI\Enums\ToolChoiceMode;
use App\Base\AI\Services\LlmClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

const TEST_API_BASE_URL = 'https://api.example.com/v1';
const LLM_TOOL_CALLING_GREETING = 'Hello!';
const LLM_TOOL_CALLING_MOONSHOT_MODEL = 'moonshotai/kimi-k2.5';
const LLM_TOOL_CALLING_WEATHER_TOOL_DESCRIPTION = 'Look up weather by city.';

function fakeChatCompletionText(string $text = 'Hi'): void
{
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $text]]],
            'usage' => [],
        ]),
    ]);
}

function fakeAnthropicMessagesText(string $text): void
{
    Http::fake([
        '*/messages' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => $text],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]),
    ]);
}

describe('LlmClient tool calling request payloads', function () {
    it('sends tools and tool_choice in request payload when provided', function () {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => LLM_TOOL_CALLING_GREETING,
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
        ]);

        $client = new LlmClient;
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'test_tool',
                    'description' => 'A test tool',
                    'parameters' => ['type' => 'object', 'properties' => []],
                ],
            ],
        ];

        $result = $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'test-key',
            'gpt-4',
            [['role' => 'user', 'content' => 'Hi']],
            executionControls: ExecutionControls::defaults(toolChoice: ToolChoiceMode::Auto),
            tools: $tools,
        ));

        expect($result)->toHaveKey('content', LLM_TOOL_CALLING_GREETING);
        expect($result)->not->toHaveKey('tool_calls');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['tools'])
                && $body['tools'][0]['function']['name'] === 'test_tool'
                && $body['tool_choice'] === 'auto';
        });
    });

    it('does not include tools in payload when null', function () {
        fakeChatCompletionText();

        $client = new LlmClient;
        $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'test-key',
            'gpt-4',
            [['role' => 'user', 'content' => 'Hello']],
        ));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! isset($body['tools']) && ! isset($body['tool_choice']);
        });
    });

    it('omits sampling parameters from generic chat completions payloads', function () {
        fakeChatCompletionText();

        $client = new LlmClient;
        $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'test-key',
            'gpt-4',
            [['role' => 'user', 'content' => 'Hello']],
            executionControls: ExecutionControls::defaults(
                temperature: 0.3,
                topP: 0.8,
                candidateCount: 2,
                presencePenalty: 0.1,
                frequencyPenalty: 0.2,
            ),
        ));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! isset($body['temperature'])
                && ! isset($body['top_p'])
                && ! isset($body['n'])
                && ! isset($body['presence_penalty'])
                && ! isset($body['frequency_penalty']);
        });
    });
});

describe('LlmClient provider-specific request payloads', function () {

    it('maps GitHub Copilot IDE headers through provider request mapping', function () {
        fakeChatCompletionText();

        $client = new LlmClient;
        $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'copilot-key',
            'gpt-4',
            [['role' => 'user', 'content' => 'Hello']],
            providerName: 'github-copilot',
        ));

        Http::assertSent(function ($request) {
            return $request->hasHeader('User-Agent', ['GitHubCopilotChat/0.35.0'])
                && $request->hasHeader('Editor-Version', ['vscode/1.107.0'])
                && $request->hasHeader('Editor-Plugin-Version', ['copilot-chat/0.35.0'])
                && $request->hasHeader('Copilot-Integration-Id', ['vscode-chat'])
                && $request->hasHeader('Authorization', ['Bearer copilot-key']);
        });
    });

    it('omits sampling for Moonshot chat completions', function (string $model, ReasoningMode $reasoningMode) {
        fakeChatCompletionText();

        $client = new LlmClient;
        $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'test-key',
            $model,
            [['role' => 'user', 'content' => 'Hello']],
            executionControls: ExecutionControls::defaults(
                temperature: 0.3,
                reasoningMode: $reasoningMode,
            ),
            providerName: 'moonshotai',
        ));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! isset($body['temperature']);
        });
    })->with([
        [LLM_TOOL_CALLING_MOONSHOT_MODEL, ReasoningMode::Enabled],
        ['moonshotai/kimi-k2.6', ReasoningMode::Enabled],
        ['moonshotai/kimi-k2.6', ReasoningMode::Disabled],
        ['moonshotai/kimi-k2.7', ReasoningMode::Enabled],
        [LLM_TOOL_CALLING_MOONSHOT_MODEL, ReasoningMode::Disabled],
    ]);

    it('does not report provider mapping adjustments for Moonshot sampling', function () {
        fakeChatCompletionText();

        $client = new LlmClient;
        $result = $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'test-key',
            LLM_TOOL_CALLING_MOONSHOT_MODEL,
            [['role' => 'user', 'content' => 'Hello']],
            executionControls: ExecutionControls::defaults(
                temperature: 0.3,
                topP: 0.8,
            ),
            providerName: 'moonshotai',
        ));

        expect($result['provider_mapping']['control_adjustments'] ?? [])
            ->toBe([]);
    });

    it('does not rewrite Moonshot temperature for models outside the Kimi-K family', function () {
        fakeChatCompletionText();

        $client = new LlmClient;
        $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'test-key',
            'moonshotai/kimi-latest',
            [['role' => 'user', 'content' => 'Hello']],
            executionControls: ExecutionControls::defaults(temperature: 0.3),
            providerName: 'moonshotai',
        ));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! isset($body['temperature']);
        });
    });
});

describe('LlmClient Moonshot and Anthropic tool payloads', function () {

    it('keeps Moonshot tool schemas unchanged', function () {
        fakeChatCompletionText();

        $client = new LlmClient;
        $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'test-key',
            'moonshot-v1-auto',
            [['role' => 'user', 'content' => 'Hello']],
            providerName: 'moonshotai',
            executionControls: ExecutionControls::defaults(toolChoice: ToolChoiceMode::Auto),
            tools: [[
                'type' => 'function',
                'function' => [
                    'name' => 'notify_user',
                    'description' => 'Notify a user or everyone.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'user_id' => [
                                'oneOf' => [
                                    ['type' => 'integer'],
                                    ['type' => 'string', 'enum' => ['all']],
                                ],
                            ],
                        ],
                    ],
                ],
            ]],
        ));

        Http::assertSent(function ($request) {
            $body = $request->data();
            $userIdSchema = $body['tools'][0]['function']['parameters']['properties']['user_id'] ?? null;

            return is_array($userIdSchema)
                && isset($userIdSchema['oneOf'])
                && ! isset($userIdSchema['anyOf']);
        });
    });

    it('sends Anthropic Messages payloads with native thinking headers and tool-choice adjustments', function () {
        fakeAnthropicMessagesText(LLM_TOOL_CALLING_GREETING);

        $client = new LlmClient;
        $tools = [[
            'type' => 'function',
            'function' => [
                'name' => 'lookup_weather',
                'description' => LLM_TOOL_CALLING_WEATHER_TOOL_DESCRIPTION,
                'parameters' => ['type' => 'object', 'properties' => []],
            ],
        ]];

        $result = $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'anthropic-key',
            'claude-sonnet-4-6',
            [['role' => 'user', 'content' => 'Check the weather in Kuala Lumpur']],
            executionControls: ExecutionControls::defaults(
                toolChoice: ToolChoiceMode::Required,
                reasoningMode: ReasoningMode::Enabled,
                reasoningVisibility: ReasoningVisibility::Summary,
                reasoningBudget: 1500,
            ),
            providerName: 'anthropic',
            tools: $tools,
            apiType: AiApiType::AnthropicMessages,
        ));

        expect($result['provider_mapping']['control_adjustments'] ?? [])
            ->toHaveCount(1)
            ->and($result['provider_mapping']['control_adjustments'][0]['control'] ?? null)->toBe('tools.choice')
            ->and($result['provider_mapping']['control_adjustments'][0]['applied_value'] ?? null)->toBe('auto');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/messages')
                && ($body['thinking']['type'] ?? null) === 'enabled'
                && ($body['thinking']['budget_tokens'] ?? null) === 1500
                && ($body['tool_choice']['type'] ?? null) === 'auto'
                && $request->hasHeader('x-api-key', ['anthropic-key'])
                && $request->hasHeader('anthropic-version', ['2023-06-01'])
                && $request->hasHeader('anthropic-beta', ['interleaved-thinking-2025-05-14']);
        });
    });

    it('omits sampling parameters from anthropic payloads', function () {
        fakeAnthropicMessagesText(LLM_TOOL_CALLING_GREETING);

        $client = new LlmClient;
        $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'anthropic-key',
            'claude-sonnet-4-6',
            [['role' => 'user', 'content' => 'Hello']],
            executionControls: ExecutionControls::defaults(
                temperature: 0.3,
                topP: 0.8,
            ),
            providerName: 'anthropic',
            apiType: AiApiType::AnthropicMessages,
        ));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! isset($body['temperature']) && ! isset($body['top_p']);
        });
    });
});

describe('LlmClient tool calling response parsing', function () {
    it('parses tool_calls from response when present', function () {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [
                                [
                                    'id' => 'call_abc123',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'artisan',
                                        'arguments' => '{"command": "route:list"}',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 20],
            ]),
        ]);

        $client = new LlmClient;
        $result = $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'test-key',
            'gpt-4',
            [['role' => 'user', 'content' => 'List routes']],
            tools: [['type' => 'function', 'function' => ['name' => 'artisan', 'description' => 'Run artisan', 'parameters' => ['type' => 'object', 'properties' => []]]]],
        ));

        expect($result)->toHaveKey('tool_calls');
        expect($result['tool_calls'])->toHaveCount(1);
        expect($result['tool_calls'][0]['function']['name'])->toBe('artisan');
        expect($result['tool_calls'][0]['id'])->toBe('call_abc123');
    });

    it('does not include tool_calls key when response has none', function () {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Just text']]],
                'usage' => [],
            ]),
        ]);

        $client = new LlmClient;
        $result = $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'test-key',
            'gpt-4',
            [['role' => 'user', 'content' => 'Hello']],
        ));

        expect($result)->not->toHaveKey('tool_calls');
        expect($result)->toHaveKey('content', 'Just text');
    });

    it('returns a helpful error when a provider responds with HTML', function () {
        Http::fake([
            '*/chat/completions' => Http::response('<!DOCTYPE html><html><body>Page Not Found</body></html>', 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]),
        ]);

        $client = new LlmClient;
        $result = $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'test-key',
            'gpt-4',
            [['role' => 'user', 'content' => 'Hello']],
        ));

        expect($result)
            ->toHaveKey('runtime_error')
            ->and($result['runtime_error'])->toBeInstanceOf(AiRuntimeError::class)
            ->and($result['runtime_error']->errorType)->toBe(AiErrorType::HtmlResponse)
            ->and($result['runtime_error']->hint)->toContain('base URL points to the API endpoint');
    });
});

describe('LlmClient reasoning and Anthropic response parsing', function () {

    it('preserves reasoning_content from chat completions responses', function () {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'reasoning_content' => 'Need tool output before answering.',
                            'tool_calls' => [
                                [
                                    'id' => 'call_reasoning_1',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'artisan',
                                        'arguments' => '{"command":"route:list"}',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 9],
            ]),
        ]);

        $client = new LlmClient;
        $result = $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'test-key',
            LLM_TOOL_CALLING_MOONSHOT_MODEL,
            [['role' => 'user', 'content' => 'List routes']],
            providerName: 'moonshotai',
            tools: [['type' => 'function', 'function' => ['name' => 'artisan', 'description' => 'Run artisan', 'parameters' => ['type' => 'object', 'properties' => []]]]],
        ));

        expect($result['reasoning_content'] ?? null)->toBe('Need tool output before answering.')
            ->and($result['tool_calls'][0]['id'] ?? null)->toBe('call_reasoning_1');
    });

    it('preserves Anthropic thinking blocks and tool calls from Messages responses', function () {
        Http::fake([
            '*/messages' => Http::response([
                'content' => [
                    [
                        'type' => 'thinking',
                        'thinking' => 'Need a tool before I can answer.',
                        'signature' => 'sig_123',
                    ],
                    [
                        'type' => 'tool_use',
                        'id' => 'toolu_123',
                        'name' => 'lookup_weather',
                        'input' => ['city' => 'Kuala Lumpur'],
                    ],
                ],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 9],
            ]),
        ]);

        $client = new LlmClient;
        $result = $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'anthropic-key',
            'claude-sonnet-4-6',
            [['role' => 'user', 'content' => 'Need the weather in Kuala Lumpur']],
            executionControls: ExecutionControls::defaults(
                reasoningMode: ReasoningMode::Enabled,
                reasoningVisibility: ReasoningVisibility::Summary,
                reasoningBudget: 1500,
            ),
            providerName: 'anthropic',
            tools: [[
                'type' => 'function',
                'function' => [
                    'name' => 'lookup_weather',
                    'description' => LLM_TOOL_CALLING_WEATHER_TOOL_DESCRIPTION,
                    'parameters' => ['type' => 'object', 'properties' => []],
                ],
            ]],
            apiType: AiApiType::AnthropicMessages,
        ));

        expect($result['tool_calls'][0]['id'] ?? null)->toBe('toolu_123')
            ->and($result['tool_calls'][0]['function']['arguments'] ?? null)->toBe('{"city":"Kuala Lumpur"}')
            ->and($result['reasoning_blocks'][0]['signature'] ?? null)->toBe('sig_123');
    });
});

describe('LlmClient tool calling responses api handling', function () {
    it('omits temperature from responses api payloads', function () {
        Http::fake([
            '*/responses' => Http::response([
                'output' => [
                    [
                        'type' => 'message',
                        'content' => [
                            ['type' => 'output_text', 'text' => LLM_TOOL_CALLING_GREETING],
                        ],
                    ],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $client = new LlmClient;
        $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'test-key',
            'gpt-5.4',
            [['role' => 'user', 'content' => 'Hello']],
            executionControls: ExecutionControls::defaults(temperature: 0.7),
            apiType: AiApiType::OpenAiResponses,
        ));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! isset($body['temperature'])
                && isset($body['max_output_tokens'])
                && $body['model'] === 'gpt-5.4';
        });
    });

    it('composes user message from label and provider diagnostic for bad requests', function () {
        Http::fake([
            '*/responses' => Http::response([
                'error' => [
                    'message' => "Unsupported parameter: 'temperature' is not supported with this model.",
                ],
            ], 400),
        ]);

        $client = new LlmClient;
        $result = $client->chat(new ChatRequest(
            TEST_API_BASE_URL,
            'test-key',
            'gpt-5.4',
            [['role' => 'user', 'content' => 'Hello']],
            apiType: AiApiType::OpenAiResponses,
        ));

        expect($result)
            ->toHaveKey('runtime_error')
            ->and($result['runtime_error'])->toBeInstanceOf(AiRuntimeError::class)
            ->and($result['runtime_error']->errorType)->toBe(AiErrorType::BadRequest)
            ->and($result['runtime_error']->userMessage)->toStartWith(AiErrorType::BadRequest->userMessage())
            ->and($result['runtime_error']->userMessage)->toContain("Unsupported parameter: 'temperature'")
            ->and($result['runtime_error']->hint)->toContain("Unsupported parameter: 'temperature'");
    });
});

describe('LlmClient anthropic streaming handling', function () {
    it('streams Anthropic thinking and tool-call deltas while preserving reasoning blocks', function () {
        $payload = <<<'SSE'
event: message_start
data: {"type":"message_start","message":{"usage":{"input_tokens":12}}}

event: content_block_start
data: {"type":"content_block_start","index":0,"content_block":{"type":"thinking","thinking":"","signature":null}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":"Need a tool."}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"signature_delta","signature":"sig_stream"}}

event: content_block_stop
data: {"type":"content_block_stop","index":0}

event: content_block_start
data: {"type":"content_block_start","index":1,"content_block":{"type":"tool_use","id":"toolu_stream","name":"lookup_weather","input":{}}}

event: content_block_delta
data: {"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"{\"city\":\"Kua"}}

event: content_block_delta
data: {"type":"content_block_delta","index":1,"delta":{"type":"input_json_delta","partial_json":"la Lumpur\"}"}}

event: message_delta
data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":9}}

event: message_stop
data: {"type":"message_stop"}
SSE;

        Http::fake([
            '*/messages' => Http::response($payload, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $client = new LlmClient;
        $events = iterator_to_array($client->chatStream(new ChatRequest(
            TEST_API_BASE_URL,
            'anthropic-key',
            'claude-sonnet-4-6',
            [['role' => 'user', 'content' => 'Need the weather in Kuala Lumpur']],
            executionControls: ExecutionControls::defaults(
                reasoningMode: ReasoningMode::Enabled,
                reasoningVisibility: ReasoningVisibility::Summary,
                reasoningBudget: 1500,
            ),
            providerName: 'anthropic',
            tools: [[
                'type' => 'function',
                'function' => [
                    'name' => 'lookup_weather',
                    'description' => LLM_TOOL_CALLING_WEATHER_TOOL_DESCRIPTION,
                    'parameters' => ['type' => 'object', 'properties' => []],
                ],
            ]],
            apiType: AiApiType::AnthropicMessages,
        )));

        expect($events[0]['type'] ?? null)->toBe('thinking_delta')
            ->and($events[0]['text'] ?? null)->toBe('Need a tool.')
            ->and($events[1]['type'] ?? null)->toBe('tool_call_delta')
            ->and($events[1]['id'] ?? null)->toBe('toolu_stream')
            ->and($events[2]['arguments_delta'] ?? null)->toBe('{"city":"Kua')
            ->and($events[3]['arguments_delta'] ?? null)->toBe('la Lumpur"}')
            ->and($events[4]['type'] ?? null)->toBe('done')
            ->and($events[4]['reasoning_blocks'][0]['signature'] ?? null)->toBe('sig_stream')
            ->and($events[4]['usage']['prompt_tokens'] ?? null)->toBe(12)
            ->and($events[4]['usage']['completion_tokens'] ?? null)->toBe(9);
    });
});
