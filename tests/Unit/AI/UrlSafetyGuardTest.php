<?php

use App\Base\AI\Services\UrlSafetyGuard;

it('blocks loopback and metadata targets', function (): void {
    $guard = new UrlSafetyGuard;

    expect($guard->validate('http://127.0.0.1/'))->toBeString()
        ->and($guard->validate('http://localhost/'))->toBeString()
        ->and($guard->validate('http://169.254.169.254/latest/meta-data'))->toBeString()
        ->and($guard->validate('ftp://example.com/'))->toBeString();
});

it('does not pin when pinning is unnecessary or bypassed', function (): void {
    $guard = new UrlSafetyGuard;

    // IP-literal host: already concrete, nothing to rebind.
    expect($guard->pinnedIpFor('203.0.113.4'))->toBeNull()
        // Private networks explicitly allowed: pinning would fight the operator's intent.
        ->and($guard->pinnedIpFor('internal.host', allowPrivateNetwork: true))->toBeNull()
        // Allowlisted host: the allowlist deliberately bypasses IP checks.
        ->and($guard->pinnedIpFor('internal.host', hostnameAllowlist: ['internal.host']))->toBeNull();
});
