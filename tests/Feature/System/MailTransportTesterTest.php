<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\System\Services\MailRuntimeSettings;
use App\Base\System\Services\MailTransportTester;

test('a connection failure is classified and does not leak the configured credential', function (): void {
    $settings = app(SettingsService::class);
    // A closed local port: no network dependency, deterministic and fast —
    // this is a real Symfony TransportExceptionInterface, not a fake one.
    $settings->set(MailRuntimeSettings::HOST_KEY, '127.0.0.1');
    $settings->set(MailRuntimeSettings::PORT_KEY, 1);
    $settings->set(MailRuntimeSettings::USERNAME_KEY, 'super-secret-username');
    $settings->set(MailRuntimeSettings::PASSWORD_KEY, 'super-secret-password');
    $settings->set(MailRuntimeSettings::FROM_ADDRESS_KEY, 'noreply@example.test');

    $result = app(MailTransportTester::class)->send('operator@example.test');

    expect($result['ok'])->toBeFalse()
        ->and($result['category'])->toBe('connection')
        ->and($result['message'])->not->toContain('super-secret-username')
        ->and($result['message'])->not->toContain('super-secret-password');
});

test('an unbuildable transport configuration is reported without throwing', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(MailRuntimeSettings::SCHEME_KEY, "not\na-valid-scheme");
    $settings->set(MailRuntimeSettings::HOST_KEY, '127.0.0.1');
    $settings->set(MailRuntimeSettings::PORT_KEY, 1);

    $result = app(MailTransportTester::class)->send('operator@example.test');

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->not->toBe('');
});
