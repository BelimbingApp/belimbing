<?php

use App\Modules\Core\AI\Services\Browser\BrowserSsrfGuard;
use Tests\TestCase;

uses(TestCase::class);

const BROWSER_SSRF_PRIVATE_IP = 'https://192.168.1.1';

dataset('browser ssrf blocked hostnames', [
    ['http://localhost', 'Blocked'],
    ['https://0.0.0.0', 'Blocked'],
    ['https://[::1]', 'Blocked'],
    ['https://router.local', 'Blocked'],
]);

dataset('browser ssrf private ips', [
    BROWSER_SSRF_PRIVATE_IP,
    'https://10.0.0.1',
]);

beforeEach(function (): void {
    $this->guard = new BrowserSsrfGuard;
});

describe('url parsing', function () {
    it('rejects empty string', function () {
        expect($this->guard->validate(''))->toContain('Invalid URL');
    });

    it('rejects malformed URL', function () {
        expect($this->guard->validate('not-a-url'))->toContain('Invalid URL');
    });

    it('rejects ftp scheme', function () {
        expect($this->guard->validate('ftp://example.com/file'))->toContain('Only http and https');
    });

    it('rejects javascript scheme', function () {
        expect($this->guard->validate('javascript://example.com/alert(1)'))->toContain('Only http and https');
    });

    it('accepts http URL', function () {
        expect($this->guard->validate('http://example.com'))->toBe(true);
    });

    it('accepts https URL', function () {
        expect($this->guard->validate('https://example.com'))->toBe(true);
    });
});

describe('hostname blocklist', function () {
    it('blocks explicit hostname targets', function (string $url, string $expectedFragment) {
        expect($this->guard->validate($url))->toContain($expectedFragment);
    })->with('browser ssrf blocked hostnames');
});

describe('hostname allowlist', function () {
    it('allows hostname matching allowlist pattern', function () {
        config()->set('ai.tools.browser.ssrf_policy.hostname_allowlist', ['*.example.com']);

        expect($this->guard->validate('https://sub.example.com/page'))->toBe(true);
    });

    it('does not match non-matching hostnames', function () {
        config()->set('ai.tools.browser.ssrf_policy.hostname_allowlist', ['*.example.com']);
        config()->set('ai.tools.browser.ssrf_policy.allow_private_network', false);

        expect($this->guard->validate(BROWSER_SSRF_PRIVATE_IP))->toContain('private or reserved');
    });
});

describe('private network blocking', function () {
    it('blocks private IP ranges', function (string $url) {
        expect($this->guard->validate($url))->toContain('private or reserved');
    })->with('browser ssrf private ips');

    it('blocks reserved 127.x', function () {
        expect($this->guard->validate('http://127.0.0.1'))->toContain('Blocked');
    });
});

describe('allow_private_network config', function () {
    it('allows private IPs when allow_private_network is true', function () {
        config()->set('ai.tools.browser.ssrf_policy.allow_private_network', true);

        expect($this->guard->validate(BROWSER_SSRF_PRIVATE_IP))->toBe(true);
    });
});
