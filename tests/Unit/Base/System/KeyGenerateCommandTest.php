<?php

use Tests\TestCase;

uses(TestCase::class);

it('refuses to run when APP_KEY is already set', function (): void {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $this->artisan('key:generate')
        ->expectsOutputToContain('disabled in BLB')
        ->expectsOutputToContain('blb:key:rotate')
        ->assertExitCode(1);
});

it('refuses even with --force when APP_KEY is already set', function (): void {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $this->artisan('key:generate', ['--force' => true])
        ->expectsOutputToContain('disabled in BLB')
        ->assertExitCode(1);
});

it('exposes a clear pointer to the safe rotation command', function (): void {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    $this->artisan('key:generate')
        ->expectsOutputToContain('php artisan blb:key:rotate')
        ->assertExitCode(1);
});
