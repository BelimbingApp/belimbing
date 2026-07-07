<?php

it('passes when the configuration is safe', function (): void {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('app.debug', false);
    config()->set('session.secure', true);

    $this->artisan('blb:security:check')->assertSuccessful();
});

it('fails in production when debug is on', function (): void {
    app()->detectEnvironment(fn () => 'production');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('app.debug', true);
    config()->set('session.secure', true);

    $this->artisan('blb:security:check')->assertFailed();
});

it('fails in production with an insecure session cookie', function (): void {
    app()->detectEnvironment(fn () => 'production');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('app.debug', false);
    config()->set('session.secure', false);

    $this->artisan('blb:security:check')->assertFailed();
});

it('fails when the app key is missing regardless of environment', function (): void {
    config()->set('app.key', '');

    $this->artisan('blb:security:check')->assertFailed();
});

it('only warns for a production concern outside production', function (): void {
    // Local env with debug on is a warning, not a failure.
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('app.debug', true);
    config()->set('session.secure', false);

    $this->artisan('blb:security:check')->assertSuccessful();
});
