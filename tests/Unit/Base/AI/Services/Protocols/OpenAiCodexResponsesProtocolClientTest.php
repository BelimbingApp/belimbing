<?php

use App\Base\AI\DTO\ChatRequest;
use App\Base\AI\DTO\ExecutionControls;
use App\Base\AI\Enums\AiApiType;
use App\Base\AI\Services\LlmClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

test('LlmClient uses the Codex SSE contract for sync chat calls', function (): void {
    $sse = <<<'SSE'
event: response.created
data: {"type":"response.created","response":{"id":"resp_test"}}

event: response.output_item.added
data: {"type":"response.output_item.added","item":{"id":"msg_test","type":"message","status":"in_progress","content":[],"phase":"final_answer","role":"assistant"}}

event: response.output_text.delta
data: {"type":"response.output_text.delta","content_index":0,"delta":"OK","item_id":"msg_test","output_index":0}

event: response.completed
data: {"type":"response.completed","response":{"status":"completed","usage":{"input_tokens":10,"output_tokens":2}}}

SSE;

    Http::fake([
        'https://chatgpt.com/backend-api/codex/responses' => Http::response($sse, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $client = new LlmClient;
    $result = $client->chat(new ChatRequest(
        baseUrl: 'https://chatgpt.com/backend-api',
        apiKey: 'codex-token',
        model: 'gpt-5.4',
        messages: [['role' => 'user', 'content' => 'Reply with OK only.']],
        executionControls: ExecutionControls::defaults(maxOutputTokens: 16, temperature: null),
        providerName: 'openai-codex',
        apiType: AiApiType::OpenAiCodexResponses,
        providerHeaders: ['chatgpt-account-id' => 'acct_test'],
    ));

    expect($result['content'] ?? null)->toBe('OK')
        ->and($result['usage']['prompt_tokens'] ?? null)->toBe(10)
        ->and($result['usage']['completion_tokens'] ?? null)->toBe(2);

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $request->url() === 'https://chatgpt.com/backend-api/codex/responses'
            && ($body['stream'] ?? null) === true
            && ! array_key_exists('max_output_tokens', $body)
            && $request->hasHeader('originator', ['pi'])
            && $request->hasHeader('OpenAI-Beta', ['responses=experimental'])
            && $request->hasHeader('Accept', ['text/event-stream'])
            && $request->hasHeader('Content-Type', ['application/json'])
            && $request->hasHeader('User-Agent', ['pi (php)'])
            && $request->hasHeader('chatgpt-account-id', ['acct_test']);
    });
});
