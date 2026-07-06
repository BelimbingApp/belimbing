<?php

use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/__ip_probe', fn () => request()->ip());
});

it('ignores a forged X-Forwarded-For from an untrusted peer', function (): void {
    // A public client connecting directly is not a trusted proxy, so its
    // forwarded header must not override the real peer address.
    $response = $this->call('GET', '/__ip_probe', server: [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
    ]);

    expect($response->getContent())->toBe('203.0.113.9');
});

it('honours X-Forwarded-For from the trusted loopback proxy', function (): void {
    // Caddy/FrankenPHP forwards from loopback (a trusted proxy by default),
    // so the client address it reports is authoritative.
    $response = $this->call('GET', '/__ip_probe', server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
    ]);

    expect($response->getContent())->toBe('198.51.100.7');
});
