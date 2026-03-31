<?php

use App\Base\Foundation\Enums\BlbErrorCode;
use App\Base\Foundation\Exceptions\BlbException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Trust reverse proxy headers (Caddy) so Laravel can correctly detect HTTPS
        // and generate https:// URLs behind the proxy.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'authz' => \App\Base\Authz\Middleware\AuthorizeCapability::class,
        ]);

        // Add database connection recovery middleware to web group
        $middleware->web(append: [
            \App\Base\Database\Middleware\DatabaseConnectionRecovery::class,
            \App\Base\Locale\Middleware\ApplyLocaleContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->report(function (BlbException $exception): void {
            Log::error('BLB framework exception', [
                'exception' => $exception::class,
                'reason_code' => $exception->reasonCode->value,
                'context' => $exception->context,
            ]);
        });

        $exceptions->render(function (BlbException $exception, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $status = match ($exception->reasonCode) {
                BlbErrorCode::BLB_DATA_CONTRACT,
                BlbErrorCode::LARA_AGENT_ID_TYPE_INVALID,
                BlbErrorCode::AUTHZ_UNKNOWN_CAPABILITY => 422,
                BlbErrorCode::AUTHZ_DENIED => 403,
                BlbErrorCode::BLB_INVARIANT_VIOLATION,
                BlbErrorCode::CIRCULAR_SEEDER_DEPENDENCY,
                BlbErrorCode::LICENSEE_COMPANY_DELETION_FORBIDDEN,
                BlbErrorCode::SYSTEM_EMPLOYEE_DELETION_FORBIDDEN => 409,
                default => 500,
            };

            $debug = (bool) config('app.debug', false);

            $payload = [
                'message' => $debug
                    ? $exception->getMessage()
                    : __('An internal framework error occurred.'),
                'reason_code' => $exception->reasonCode->value,
            ];

            if ($debug && $exception->context !== []) {
                $payload['context'] = $exception->context;
            }

            return response()->json($payload, $status);
        });
    })->create();
