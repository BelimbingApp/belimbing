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

it('allows loopback and private ranges when allowPrivateNetwork is true', function (): void {
    $guard = new UrlSafetyGuard;

    // IP-literal loopback and private addresses.
    expect($guard->validate('http://127.0.0.1:11434', allowPrivateNetwork: true))->toBeTrue()
        ->and($guard->validate('http://10.0.0.5', allowPrivateNetwork: true))->toBeTrue()
        ->and($guard->validate('http://192.168.1.100', allowPrivateNetwork: true))->toBeTrue()
        ->and($guard->validate('http://172.16.0.1', allowPrivateNetwork: true))->toBeTrue()
        // Public IPs remain allowed.
        ->and($guard->validate('https://8.8.8.8', allowPrivateNetwork: true))->toBeTrue();
});

it('allows localhost hostname when allowPrivateNetwork is true', function (): void {
    // Use a fake resolver so localhost resolves to 127.0.0.1.
    $guard = new UrlSafetyGuard(fn (string $host) => ['127.0.0.1']);

    expect($guard->validate('http://localhost:11434', allowPrivateNetwork: true))->toBeTrue();
});

it('blocks cloud metadata and link-local even when allowPrivateNetwork is true', function (): void {
    $guard = new UrlSafetyGuard;

    // Link-local / cloud metadata IPs — always blocked.
    expect($guard->validate('http://169.254.169.254/latest/meta-data', allowPrivateNetwork: true))->toBeString()
        ->and($guard->validate('http://169.254.170.2', allowPrivateNetwork: true))->toBeString()
        // Multicast — always blocked.
        ->and($guard->validate('http://224.0.0.1', allowPrivateNetwork: true))->toBeString()
        // Current network — always blocked.
        ->and($guard->validate('http://0.0.0.0', allowPrivateNetwork: true))->toBeString();
});

it('blocks hostnames resolving to metadata even when allowPrivateNetwork is true', function (): void {
    // A hostname that resolves to a cloud metadata IP must be blocked
    // even when private networks are allowed.
    $guard = new UrlSafetyGuard(fn (string $host) => ['169.254.169.254']);

    expect($guard->validate('http://metadata-service.local/latest/meta-data', allowPrivateNetwork: true))->toBeString()
        ->and($guard->validate('https://169.254.169.254', allowPrivateNetwork: true))->toBeString();
});
