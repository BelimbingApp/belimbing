<?php

use App\Base\AI\Exceptions\GithubCopilotAuthException;
use App\Base\AI\Services\GithubCopilotAuthService;
use App\Base\Integration\Models\OutboundExchange;
use Illuminate\Support\Facades\Http;

it('records github copilot device flow and copilot token exchanges', function (): void {
    Http::fake([
        'https://github.com/login/device/code' => Http::response([
            'device_code' => 'device-secret',
            'user_code' => 'ABCD-1234',
            'verification_uri' => 'https://github.com/login/device',
            'expires_in' => 900,
            'interval' => 5,
        ]),
        'https://github.com/login/oauth/access_token' => Http::response([
            'access_token' => 'ghu-secret',
        ]),
        'https://api.github.com/copilot_internal/v2/token' => Http::response([
            'token' => 'proxy-ep=https://proxy.individual.githubcopilot.com;token=secret',
            'expires_at' => time() + 3600,
        ]),
    ]);

    $service = app(GithubCopilotAuthService::class);
    $device = $service->requestDeviceCode();
    $poll = $service->pollForAccessToken($device['device_code']);
    $copilot = $service->exchangeForCopilotToken((string) $poll['token']);

    expect($copilot['base_url'])->toBe('https://api.individual.githubcopilot.com')
        ->and(OutboundExchange::query()->where('operation', 'ai.github_copilot.device_code.request')->exists())->toBeTrue()
        ->and(OutboundExchange::query()->where('operation', 'ai.github_copilot.device_token.poll')->exists())->toBeTrue()
        ->and(OutboundExchange::query()->where('operation', 'ai.github_copilot.copilot_token.exchange')->exists())->toBeTrue();

    $exchange = OutboundExchange::query()->where('operation', 'ai.github_copilot.device_code.request')->firstOrFail();
    expect($exchange->request_body['value']['device_code'] ?? null)->toBeNull()
        ->and($exchange->response_body['value']['device_code'])->toBe('device-secret');
});

it('includes exchange id when github copilot token exchange fails', function (): void {
    Http::fake([
        'https://api.github.com/copilot_internal/v2/token' => Http::response(['message' => 'bad'], 401),
    ]);

    expect(fn () => app(GithubCopilotAuthService::class)->exchangeForCopilotToken('ghu-secret-fail'))
        ->toThrow(function (GithubCopilotAuthException $exception): void {
            $exchange = OutboundExchange::query()->firstOrFail();

            expect($exception->exchangeId)->toBe($exchange->id)
                ->and($exception->getMessage())->toContain('Integration exchange [ix_')
                ->and($exchange->request_headers['Authorization'])->toBe('[redacted]');
        });
});
