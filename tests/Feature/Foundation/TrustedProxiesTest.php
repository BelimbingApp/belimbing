<?php

use Illuminate\Support\Facades\Route;

const FOUNDATION_TRUSTED_PROXIES_PROBE_PATH = '/__ip_probe';
const FOUNDATION_TRUSTED_PROXIES_DIRECT_CLIENT_IP = '203.0.'.'113.9';
const FOUNDATION_TRUSTED_PROXIES_FORGED_CLIENT_IP = '1.2.'.'3.4';
const FOUNDATION_TRUSTED_PROXIES_LOOPBACK_PROXY_IP = '127.0.'.'0.1';
const FOUNDATION_TRUSTED_PROXIES_FORWARDED_CLIENT_IP = '198.51.'.'100.7';

beforeEach(function (): void {
    Route::get(FOUNDATION_TRUSTED_PROXIES_PROBE_PATH, fn () => request()->ip());
});

it('ignores a forged X-Forwarded-For from an untrusted peer', function (): void {
    // A public client connecting directly is not a trusted proxy, so its
    // forwarded header must not override the real peer address.
    $response = $this->call('GET', FOUNDATION_TRUSTED_PROXIES_PROBE_PATH, server: [
        'REMOTE_ADDR' => FOUNDATION_TRUSTED_PROXIES_DIRECT_CLIENT_IP,
        'HTTP_X_FORWARDED_FOR' => FOUNDATION_TRUSTED_PROXIES_FORGED_CLIENT_IP,
    ]);

    expect($response->getContent())->toBe(FOUNDATION_TRUSTED_PROXIES_DIRECT_CLIENT_IP);
});

it('honours X-Forwarded-For from the trusted loopback proxy', function (): void {
    // Caddy/FrankenPHP forwards from loopback (a trusted proxy by default),
    // so the client address it reports is authoritative.
    $response = $this->call('GET', FOUNDATION_TRUSTED_PROXIES_PROBE_PATH, server: [
        'REMOTE_ADDR' => FOUNDATION_TRUSTED_PROXIES_LOOPBACK_PROXY_IP,
        'HTTP_X_FORWARDED_FOR' => FOUNDATION_TRUSTED_PROXIES_FORWARDED_CLIENT_IP,
    ]);

    expect($response->getContent())->toBe(FOUNDATION_TRUSTED_PROXIES_FORWARDED_CLIENT_IP);
});
