<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

use App\Modules\Core\AI\Services\ControlPlane\WireLogger;
use App\Modules\Core\AI\Services\ControlPlane\WireLogReadableFormatter;
use Illuminate\Foundation\Testing\TestCase;

uses(TestCase::class);

const WLRF_CHAT_ENDPOINT = '/v1/chat';
const WLRF_SECOND_AT = '2026-04-27T12:00:01+00:00';
const WLRF_DONE_AT = '2026-04-27T12:00:05+00:00';

/**
 * Build a wire-log entry shape matching what {@see WireLogger::preview()} returns.
 *
 * @param  array<string, mixed>  $payload
 */
function wlrfEntry(int $entryNumber, string $type, array $payload, ?string $at = null): array
{
    $at ??= '2026-04-27T12:00:'.str_pad((string) $entryNumber, 2, '0', STR_PAD_LEFT).'+00:00';
    $decoded = array_merge(['at' => $at, 'type' => $type], $payload);

    return [
        'entry_number' => $entryNumber,
        'at' => $at,
        'type' => $type,
        'summary_preview' => '',
        'payload_pretty' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'payload_truncated' => false,
        'preview_status' => 'full',
        'raw_line' => '',
        'decoded_payload' => $decoded,
    ];
}

function wlrfStreamChunk(int $entryNumber, array $delta, ?string $finishReason = null, ?string $at = null, array $extraChoiceKeys = []): array
{
    $choice = array_merge(['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason], $extraChoiceKeys);
    $rawLine = 'data: '.json_encode(['choices' => [$choice]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return wlrfEntry($entryNumber, 'llm.stream_line', ['raw_line' => $rawLine], $at);
}

it('returns an empty result when no entries are present', function (): void {
    $result = (new WireLogReadableFormatter)->format([]);

    expect($result['has_entries'])->toBeFalse()
        ->and($result['attempts'])->toBe([])
        ->and($result['anomalies'])->toBe([])
        ->and($result['overview']['total_entries'])->toBe(0);
});

it('groups adjacent stream chunks into a single block and reassembles content', function (): void {
    $entries = [
        wlrfStreamChunk(1, ['content' => 'Hello, ']),
        wlrfStreamChunk(2, ['content' => 'world!']),
        wlrfStreamChunk(3, [], finishReason: 'stop'),
    ];

    $result = (new WireLogReadableFormatter)->format($entries);

    expect($result['attempts'])->toHaveCount(1);
    $sections = $result['attempts'][0]['sections'];

    expect($sections)->toHaveCount(1)
        ->and($sections[0]['kind'])->toBe('stream_block')
        ->and($sections[0]['reassembled_content'])->toBe('Hello, world!')
        ->and($sections[0]['finish_reason'])->toBe('stop')
        ->and($sections[0]['first_entry_number'])->toBe(1)
        ->and($sections[0]['last_entry_number'])->toBe(3)
        ->and($sections[0]['chunk_count'])->toBe(3);
});

it('reassembles tool-call arguments and marks valid JSON', function (): void {
    $entries = [
        wlrfStreamChunk(1, ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'lookup_user']]]]),
        wlrfStreamChunk(2, ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"id":']]]]),
        wlrfStreamChunk(3, ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '42}']]]]),
        wlrfStreamChunk(4, [], finishReason: 'tool_calls'),
    ];

    $result = (new WireLogReadableFormatter)->format($entries);
    $block = $result['attempts'][0]['sections'][0];

    expect($block['tool_calls'])->toHaveCount(1)
        ->and($block['tool_calls'][0]['name'])->toBe('lookup_user')
        ->and($block['tool_calls'][0]['arguments'])->toBe('{"id":42}')
        ->and($block['tool_calls'][0]['arguments_valid_json'])->toBeTrue()
        ->and($block['tool_calls'][0]['source_entries'])->toContain(1, 2, 3)
        ->and($block['finish_reason'])->toBe('tool_calls');
});

it('flags invalid tool-args JSON as an operator-visible failure', function (): void {
    $entries = [
        wlrfStreamChunk(1, ['tool_calls' => [['index' => 0, 'function' => ['name' => 'lookup_user', 'arguments' => '{"id":']]]]),
        wlrfStreamChunk(2, [], finishReason: 'tool_calls'),
    ];

    $result = (new WireLogReadableFormatter)->format($entries);
    $block = $result['attempts'][0]['sections'][0];

    expect($block['tool_calls'][0]['arguments_valid_json'])->toBeFalse()
        ->and($block['tool_calls'][0]['arguments_parse_error'])->not->toBeNull();
});

it('records unknown delta keys as an anomaly signal with source entry numbers', function (): void {
    $entries = [
        wlrfStreamChunk(1, ['content' => 'hi']),
        wlrfStreamChunk(2, ['content' => 'there', 'unexpected_provider_field' => 'foo']),
        wlrfStreamChunk(3, [], finishReason: 'stop'),
    ];

    $result = (new WireLogReadableFormatter)->format($entries);

    $unknownKeyAnomaly = collect($result['anomalies'])
        ->firstWhere('type', 'unknown_keys');

    expect($unknownKeyAnomaly)->not->toBeNull()
        ->and($unknownKeyAnomaly['detail'])->toContain('unexpected_provider_field')
        ->and($unknownKeyAnomaly['entry_numbers'])->toBe([2]);
});

it('flags non-2xx response status as an http_error anomaly', function (): void {
    $entries = [
        wlrfEntry(1, 'llm.request', ['endpoint' => WLRF_CHAT_ENDPOINT, 'request' => ['provider_name' => 'openai', 'model' => 'gpt-4o', 'base_url' => 'https://api.example.com', 'messages' => [], 'tools' => []]]),
        wlrfEntry(2, 'llm.response_status', ['status_code' => 503, 'stream' => false]),
        wlrfEntry(3, 'llm.error', ['stage' => 'response', 'message' => 'Service Unavailable']),
    ];

    $result = (new WireLogReadableFormatter)->format($entries);

    expect(collect($result['anomalies'])->firstWhere('type', 'http_error'))->not->toBeNull()
        ->and($result['attempts'][0]['outcome'])->toBe('failed')
        ->and($result['attempts'][0]['status_code'])->toBe(503);
});

it('segments multiple llm.request cycles into separate attempts with summaries', function (): void {
    $entries = [
        wlrfEntry(1, 'llm.request', [
            'endpoint' => WLRF_CHAT_ENDPOINT,
            'stream' => true,
            'request' => ['provider_name' => 'openai', 'model' => 'gpt-4o', 'base_url' => 'https://api.openai.com', 'messages' => [], 'tools' => []],
            'mapped' => ['payload' => ['model' => 'gpt-4o'], 'headers' => ['Authorization' => 'Bearer secret']],
        ], at: '2026-04-27T12:00:00+00:00'),
        wlrfEntry(2, 'llm.response_status', ['status_code' => 503, 'stream' => true], at: WLRF_SECOND_AT),
        wlrfEntry(3, 'llm.error', ['stage' => 'response', 'message' => 'rate limited'], at: WLRF_SECOND_AT),
        wlrfEntry(4, 'llm.request', [
            'endpoint' => '/v1/messages',
            'stream' => true,
            'request' => ['provider_name' => 'anthropic', 'model' => 'claude-opus-4', 'base_url' => 'https://api.anthropic.com', 'messages' => [], 'tools' => []],
            'mapped' => ['payload' => ['model' => 'claude-opus-4'], 'headers' => []],
        ], at: '2026-04-27T12:00:02+00:00'),
        wlrfEntry(5, 'llm.response_status', ['status_code' => 200, 'stream' => true], at: '2026-04-27T12:00:03+00:00'),
        wlrfStreamChunk(6, ['content' => 'ok'], at: '2026-04-27T12:00:04+00:00'),
        wlrfStreamChunk(7, [], finishReason: 'stop', at: WLRF_DONE_AT),
        wlrfEntry(8, 'llm.complete', ['context' => []], at: WLRF_DONE_AT),
    ];

    $result = (new WireLogReadableFormatter)->format($entries);

    expect($result['attempts'])->toHaveCount(2)
        ->and($result['attempts'][0]['provider'])->toBe('openai')
        ->and($result['attempts'][0]['outcome'])->toBe('failed')
        ->and($result['attempts'][1]['provider'])->toBe('anthropic')
        ->and($result['attempts'][1]['outcome'])->toBe('succeeded')
        ->and($result['attempts'][1]['finish_reason'])->toBe('stop')
        ->and($result['attempts'][1]['summary'])->toContain('claude-opus-4');
});

it('builds a copy-as-cURL replay payload for the outbound request with a placeholder API key', function (): void {
    $entries = [
        wlrfEntry(1, 'llm.request', [
            'endpoint' => '/v1/chat/completions',
            'stream' => true,
            'request' => ['provider_name' => 'openai', 'model' => 'gpt-4o', 'base_url' => 'https://api.openai.com', 'messages' => [], 'tools' => []],
            'mapped' => [
                'payload' => ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'hi']]],
                'headers' => ['Authorization' => 'Bearer secret-real-key', 'Content-Type' => 'application/json'],
            ],
        ]),
    ];

    $result = (new WireLogReadableFormatter)->format($entries);
    $replay = $result['attempts'][0]['replay'];

    expect($replay)->not->toBeNull()
        ->and($replay['url'])->toBe('https://api.openai.com/v1/chat/completions')
        ->and($replay['curl'])
        ->toContain('curl -X POST')
        ->toContain('https://api.openai.com/v1/chat/completions')
        ->toContain('Bearer $API_KEY')
        ->not->toContain('secret-real-key')
        ->toContain('"model": "gpt-4o"');
});

it('computes timing markers from the loaded window', function (): void {
    $entries = [
        wlrfEntry(1, 'llm.request', ['endpoint' => WLRF_CHAT_ENDPOINT, 'request' => ['provider_name' => 'p', 'model' => 'm', 'base_url' => 'https://x', 'messages' => [], 'tools' => []]], at: '2026-04-27T12:00:00+00:00'),
        wlrfEntry(2, 'llm.response_status', ['status_code' => 200], at: WLRF_SECOND_AT),
        wlrfStreamChunk(3, ['reasoning_content' => 'thinking…'], at: '2026-04-27T12:00:02+00:00'),
        wlrfStreamChunk(4, ['content' => 'reply'], at: '2026-04-27T12:00:03+00:00'),
        wlrfStreamChunk(5, ['tool_calls' => [['index' => 0, 'function' => ['name' => 'do_thing']]]], at: '2026-04-27T12:00:04+00:00'),
        wlrfStreamChunk(6, [], finishReason: 'tool_calls', at: WLRF_DONE_AT),
    ];

    $result = (new WireLogReadableFormatter)->format($entries);

    expect($result['overview']['time_to_first_byte_ms'])->toBe(2_000)
        ->and($result['overview']['time_to_first_reasoning_ms'])->toBe(2_000)
        ->and($result['overview']['time_to_first_content_ms'])->toBe(3_000)
        ->and($result['overview']['time_to_first_tool_call_ms'])->toBe(4_000)
        ->and($result['overview']['stream_chunks'])->toBe(4)
        ->and($result['overview']['attempt_count'])->toBe(1);
});

it('collapses three or more consecutive empty deltas into a heartbeat run', function (): void {
    $entries = [
        wlrfStreamChunk(1, ['content' => 'a']),
        wlrfStreamChunk(2, []),
        wlrfStreamChunk(3, []),
        wlrfStreamChunk(4, []),
        wlrfStreamChunk(5, []),
        wlrfStreamChunk(6, ['content' => 'b']),
        wlrfStreamChunk(7, [], finishReason: 'stop'),
    ];

    $result = (new WireLogReadableFormatter)->format($entries);
    $fragments = $result['attempts'][0]['sections'][0]['fragments'];
    $emptyRun = collect($fragments)->firstWhere('kind', 'empty_run');

    expect($emptyRun)->not->toBeNull()
        ->and($emptyRun['count'])->toBe(4)
        ->and($emptyRun['first_entry_number'])->toBe(2)
        ->and($emptyRun['last_entry_number'])->toBe(5);
});

it('keeps each fragment cross-linked to its source entry_number', function (): void {
    $entries = [
        wlrfStreamChunk(7, ['content' => 'hi']),
        wlrfStreamChunk(8, [], finishReason: 'stop'),
    ];

    $result = (new WireLogReadableFormatter)->format($entries);
    $fragments = $result['attempts'][0]['sections'][0]['fragments'];

    expect($fragments[0]['entry_number'])->toBe(7)
        ->and($fragments[1]['entry_number'])->toBe(8);
});
