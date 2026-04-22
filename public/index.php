<?php

define('LARAVEL_START', microtime(true));

// Catch fatal errors before Laravel boots so the browser shows the error instead of 502
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null || headers_sent() || headers_list() !== []) {
        return;
    }
    $fatals = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (! in_array($err['type'], $fatals, true)) {
        return;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    $msg = htmlspecialchars($err['message'], ENT_QUOTES, 'UTF-8');
    $file = htmlspecialchars($err['file'] ?? 'unknown', ENT_QUOTES, 'UTF-8');
    $line = (int) ($err['line'] ?? 0);
    echo '<!DOCTYPE html><html><head><title>Fatal Error</title></head><body>';
    echo "<h1>Fatal Error</h1><pre>{$msg}\n\nin {$file} on line {$line}</pre></body></html>";
});

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
