<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Base\AI\DTO\AiRuntimeError;
use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\Enums\AiErrorType;
use App\Base\AI\Services\LlmClient;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;

uses(TestCase::class);

const TEST_API_BASE_URL = 'https://api.example.com/v1';

describe('LlmClient tool calling', function () {
    it('sends tools and tool_choice in request payload when provided', function () {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Hello!',
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
            tools: $tools,
            toolChoice: 'auto',
        ));

        expect($result)->toHaveKey('content', 'Hello!');
        expect($result)->not->toHaveKey('tool_calls');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['tools'])
                && $body['tools'][0]['function']['name'] === 'test_tool'
                && $body['tool_choice'] === 'auto';
        });
    });

    it('does not include tools in payload when null', function () {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi']]],
                'usage' => [],
            ]),
        ]);

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
