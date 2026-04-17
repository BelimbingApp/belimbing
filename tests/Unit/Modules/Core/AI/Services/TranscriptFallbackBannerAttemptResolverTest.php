<?php

use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Services\TranscriptFallbackBannerAttemptResolver;

function tfbMessage(
    string $role,
    string $type = 'message',
    array $meta = [],
    string $content = '',
): Message {
    return new Message(
        role: $role,
        content: $content,
        timestamp: new DateTimeImmutable('2026-01-01 12:00:00'),
        meta: $meta,
        type: $type,
    );
}

it('returns null for an empty transcript', function (): void {
    expect(TranscriptFallbackBannerAttemptResolver::latestFailureAttempt([]))->toBeNull();
});

it('returns null when the latest assistant message line has no fallback attempts', function (): void {
    $messages = [
        tfbMessage('user', content: 'Hi'),
        tfbMessage('assistant', meta: ['model' => 'gpt-5'], content: 'Hello'),
    ];

    expect(TranscriptFallbackBannerAttemptResolver::latestFailureAttempt($messages))->toBeNull();
});

it('returns the last fallback entry from the latest assistant message line', function (): void {
    $attempts = [
        ['provider' => 'a', 'model' => 'm1', 'error' => 'e1', 'error_type' => 'rate_limit', 'latency_ms' => 1],
        ['provider' => 'b', 'model' => 'm2', 'error' => 'e2', 'error_type' => 'server_error', 'latency_ms' => 2],
    ];
    $messages = [
        tfbMessage('user', content: 'Hi'),
        tfbMessage('assistant', meta: ['fallback_attempts' => $attempts], content: 'Done'),
    ];

    $got = TranscriptFallbackBannerAttemptResolver::latestFailureAttempt($messages);

    expect($got['provider'])->toBe('b')
        ->and($got['model'])->toBe('m2')
        ->and($got['error'])->toBe('e2');
});

it('returns null when a newer assistant message succeeded without fallback after an older fallback turn', function (): void {
    $messages = [
        tfbMessage('user', content: 'First'),
        tfbMessage('assistant', meta: [
            'fallback_attempts' => [
                ['provider' => 'p', 'model' => 'm', 'error' => 'rate limited', 'error_type' => 'rate_limit', 'latency_ms' => 10],
            ],
        ], content: 'Via fallback'),
        tfbMessage('user', content: 'Second'),
        tfbMessage('assistant', meta: ['model' => 'm'], content: 'Direct OK'),
    ];

    expect(TranscriptFallbackBannerAttemptResolver::latestFailureAttempt($messages))->toBeNull();
});

it('returns null when the latest line is a terminal error even if fallback_attempts are present', function (): void {
    $messages = [
        tfbMessage('user', content: 'say hi'),
        tfbMessage('assistant', meta: [
            'error_type' => 'config_error',
            'error' => 'Configuration error. All provider configurations failed.',
            'fallback_attempts' => [
                ['provider' => 'github-copilot', 'model' => 'claude-opus-4.6', 'error' => 'Rate limit exceeded.', 'error_type' => 'rate_limit', 'latency_ms' => 10],
            ],
        ], content: '⚠ error'),
    ];

    expect(TranscriptFallbackBannerAttemptResolver::latestFailureAttempt($messages))->toBeNull();
});

it('skips non-message assistant rows when finding the latest qualifying line', function (): void {
    $messages = [
        tfbMessage('user', content: 'Q'),
        tfbMessage('assistant', type: 'thinking', meta: [], content: ''),
        tfbMessage('assistant', meta: [
            'fallback_attempts' => [
                ['provider' => 'x', 'model' => 'y', 'error' => 'boom', 'error_type' => 'rate_limit', 'latency_ms' => 5],
            ],
        ], content: 'Answer'),
    ];

    $got = TranscriptFallbackBannerAttemptResolver::latestFailureAttempt($messages);

    expect($got['provider'])->toBe('x')
        ->and($got['model'])->toBe('y');
});
