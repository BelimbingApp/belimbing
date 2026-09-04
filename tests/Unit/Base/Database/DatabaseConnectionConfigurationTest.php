<?php

use Tests\TestCase;

uses(TestCase::class);

it('keeps the SQLite read-only connection aligned with the default SQLite connection', function (): void {
    $previousValue = getenv('DB_CONNECTION');
    $previousEnvironment = $_ENV['DB_CONNECTION'] ?? null;
    $previousServer = $_SERVER['DB_CONNECTION'] ?? null;

    putenv('DB_CONNECTION=sqlite');
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_CONNECTION'] = 'sqlite';

    try {
        $connections = require config_path('database.php');

        expect($connections['connections']['readonly'])
            ->toBe($connections['connections']['sqlite']);
    } finally {
        putenv($previousValue === false ? 'DB_CONNECTION' : 'DB_CONNECTION='.$previousValue);

        if ($previousEnvironment === null) {
            unset($_ENV['DB_CONNECTION']);
        } else {
            $_ENV['DB_CONNECTION'] = $previousEnvironment;
        }

        if ($previousServer === null) {
            unset($_SERVER['DB_CONNECTION']);
        } else {
            $_SERVER['DB_CONNECTION'] = $previousServer;
        }
    }
});
