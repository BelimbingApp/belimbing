<?php

use App\Core\AI\Exceptions\InvalidSessionIdException;
use App\Core\AI\Values\SessionId;

it('accepts current and supported legacy session identifiers', function (string $sessionId): void {
    expect(SessionId::fromString($sessionId)->value)->toBe($sessionId);
})->with([
    'current timestamp and random suffix' => '20260922-120000-abc123',
    'legacy timestamp' => '20260307-235959',
    'legacy UUID v4' => '123e4567-e89b-42d3-a456-426614174000',
]);

it('rejects identifiers outside the supported grammar', function (string $sessionId): void {
    expect(fn () => SessionId::fromString($sessionId))->toThrow(InvalidSessionIdException::class);
})->with([
    'empty' => '',
    'forward slash' => '../20260922-120000-abc123',
    'backslash' => '..\\20260922-120000-abc123',
    'encoded separators' => '%2e%2e%2f20260922-120000-abc123',
    'drive prefix' => 'C:\\20260922-120000-abc123',
    'null byte' => "20260922-120000-abc123\0",
    'uppercase random suffix' => '20260922-120000-ABC123',
    'invalid calendar timestamp' => '20260230-120000-abc123',
    'non-v4 UUID' => '123e4567-e89b-12d3-a456-426614174000',
]);
