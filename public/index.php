<?php

define('LARAVEL_START', microtime(true));

// A fatal before Laravel boots (broken vendor/, bad PHP build) would otherwise
// reach the browser as a blank 500. Answer with the branded static fallback
// instead; the message itself belongs in the PHP error log, not on screen.
// When display_errors is on, PHP has already printed the error and sent
// headers, so this handler stays out of the way.
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
    $fallback = __DIR__.'/errors/5xx.html';
    if (is_file($fallback)) {
        readfile($fallback);

        return;
    }
    echo '<!DOCTYPE html><html><head><title>Server unavailable</title></head><body>';
    echo '<h1>Server unavailable</h1><p>The server did not answer. Please try again shortly.</p></body></html>';
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
