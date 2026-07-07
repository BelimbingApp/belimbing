<?php

use App\Base\AI\Services\UrlSafetyGuard;

const URL_SAFETY_METADATA_HOST = '169.254.'.'169.254';
const URL_SAFETY_IP_LITERAL = '203.0.'.'113.4';

it('blocks loopback and metadata targets', function (): void {
    $guard = new UrlSafetyGuard;

    expect($guard->validate('https://localhost/'))->toBeString()
        ->and($guard->validate('https://printer.local/'))->toBeString()
        ->and($guard->validate('https://'.URL_SAFETY_METADATA_HOST.'/latest/meta-data'))->toBeString()
        ->and($guard->validate('ftp://example.com/'))->toBeString();
});

it('does not pin when pinning is unnecessary or bypassed', function (): void {
    $guard = new UrlSafetyGuard;

    // IP-literal host: already concrete, nothing to rebind.
    expect($guard->pinnedIpFor(URL_SAFETY_IP_LITERAL))->toBeNull()
        // Private networks explicitly allowed: pinning would fight the operator's intent.
        ->and($guard->pinnedIpFor('internal.host', allowPrivateNetwork: true))->toBeNull()
        // Allowlisted host: the allowlist deliberately bypasses IP checks.
        ->and($guard->pinnedIpFor('internal.host', hostnameAllowlist: ['internal.host']))->toBeNull();
});
