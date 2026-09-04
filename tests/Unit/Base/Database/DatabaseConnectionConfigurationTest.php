<?php

use Tests\TestCase;

uses(TestCase::class);

it('keeps the SQLite read-only connection aligned with the default SQLite connection', function (): void {
    expect(config('database.connections.readonly'))
        ->toBe(config('database.connections.sqlite'));
});
