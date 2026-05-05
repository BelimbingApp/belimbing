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

// Regression: a previous implementation tried to delegate to the real
// key:generate via `passthru(php artisan key:generate ...)` from the same
// overridden command, which recursed forever in CI (.env has APP_KEY=)
// and aborted with SIGABRT (exit 134). Here we prove the empty-APP_KEY
// bootstrap path runs in-process to completion without recursion.
// `--show` is used so parent::handle() prints the candidate key and returns
// without writing to the project .env.
it('delegates to the stock command when APP_KEY is empty (fresh install / CI bootstrap)', function (): void {
    config(['app.key' => '']);

    $this->artisan('key:generate', ['--show' => true])
        ->expectsOutputToContain('base64:')
        ->assertExitCode(0);
});
