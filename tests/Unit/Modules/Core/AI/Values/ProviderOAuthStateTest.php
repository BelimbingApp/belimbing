<?php

use App\Modules\Core\AI\Values\ProviderOAuthState;
use Tests\TestCase;

uses(TestCase::class);

test('ProviderOAuthState provides reusable defaults and transitions', function (): void {
    $defaults = ProviderOAuthState::defaults();
    $pending = ProviderOAuthState::pending($defaults, 'browser_pkce');
    $connected = ProviderOAuthState::connected($pending, mode: 'browser_pkce');
    $expired = ProviderOAuthState::expired($connected, 'refresh_failed', 'Token refresh failed.', mode: 'browser_pkce');
    $disconnected = ProviderOAuthState::disconnected($expired, mode: 'browser_pkce');

    expect($defaults['status'])->toBe('disconnected')
        ->and($defaults['mode'])->toBeNull()
        ->and($pending['status'])->toBe('pending')
        ->and($pending['mode'])->toBe('browser_pkce')
        ->and($connected['status'])->toBe('connected')
        ->and($expired['status'])->toBe('expired')
        ->and($expired['last_error_code'])->toBe('refresh_failed')
        ->and($disconnected['status'])->toBe('disconnected')
        ->and($disconnected['last_error_code'])->toBeNull()
        ->and($disconnected['completed_at'])->toBeNull();
});
