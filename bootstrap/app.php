<?php

use App\Base\Authz\Enums\AuthzErrorCode;
use App\Base\Authz\Middleware\AuthorizeCapability;
use App\Base\Database\Enums\DatabaseErrorCode;
use App\Base\Database\Middleware\DatabaseConnectionRecovery;
use App\Base\Foundation\Enums\FoundationErrorCode;
use App\Base\Foundation\Exceptions\BlbException;
use App\Base\Locale\Middleware\ApplyLocaleContext;
use App\Modules\Core\AI\Enums\AIErrorCode;
use App\Modules\Core\Company\Enums\CompanyErrorCode;
use App\Modules\Core\Employee\Enums\EmployeeErrorCode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

$isLivewireInteraction = static fn (Request $request): bool => $request->hasHeader('X-Livewire')
    || $request->hasHeader('X-Livewire-Navigate')
    || str_starts_with($request->path(), 'livewire/');

$fromSameAppReferer = static function (Request $request): bool {
    $referer = (string) $request->headers->get('referer', '');

    return $referer !== ''
        && str_starts_with($referer, $request->getSchemeAndHttpHost());
};

$flashSessionExpiredWhenAppropriate = static function (Request $request) use ($isLivewireInteraction, $fromSameAppReferer): void {
    $shouldFlash = $request->expectsJson()
        || $isLivewireInteraction($request)
        || $fromSameAppReferer($request);

    if ($shouldFlash && $request->hasSession()) {
        $request->session()->flash('session_expired', true);
    }
};

$redirectToLogin = static function (Request $request, ?string $redirectTo = null) use ($flashSessionExpiredWhenAppropriate): Response {
    $flashSessionExpiredWhenAppropriate($request);

    return redirect()->guest($redirectTo ?? route('login'));
};

$renderAuthenticationException = static function (AuthenticationException $exception, Request $request) use ($redirectToLogin): Response {
    return $redirectToLogin($request, $exception->redirectTo($request));
};

$renderTokenMismatchException = static function (TokenMismatchException $_, Request $request) use ($isLivewireInteraction, $redirectToLogin): ?Response {
    if (! $isLivewireInteraction($request)) {
        return null;
    }

    return $redirectToLogin($request);
};

$renderUnauthorizedLivewireException = static function (HttpException $exception, Request $request) use ($isLivewireInteraction, $redirectToLogin): ?Response {
    if ($exception->getStatusCode() !== 401 || ! $isLivewireInteraction($request)) {
        return null;
    }

    return $redirectToLogin($request);
};

$reportBlbException = static function (BlbException $exception): void {
    Log::error('BLB platform exception', [
        'exception' => $exception::class,
        'reason_code' => $exception->reasonCode->value,
        'context' => $exception->context,
    ]);
};

$renderBlbException = static function (BlbException $exception, Request $request) {
    if (! $request->expectsJson()) {
        return null;
    }

    $status = match ($exception->reasonCode) {
        FoundationErrorCode::BLB_DATA_CONTRACT,
        AIErrorCode::LARA_AGENT_ID_TYPE_INVALID,
        AuthzErrorCode::AUTHZ_UNKNOWN_CAPABILITY => 422,
        AuthzErrorCode::AUTHZ_DENIED => 403,
        FoundationErrorCode::BLB_INVARIANT_VIOLATION,
        DatabaseErrorCode::CIRCULAR_SEEDER_DEPENDENCY,
        CompanyErrorCode::LICENSEE_COMPANY_DELETION_FORBIDDEN,
        EmployeeErrorCode::SYSTEM_EMPLOYEE_DELETION_FORBIDDEN => 409,
        default => 500,
    };

    $debug = (bool) config('app.debug', false);

    $payload = [
        'message' => $debug
            ? $exception->getMessage()
            : __('An internal Belimbing error occurred.'),
        'reason_code' => $exception->reasonCode->value,
    ];

    if ($debug && $exception->context !== []) {
        $payload['context'] = $exception->context;
    }

    return response()->json($payload, $status);
};

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
            'authz' => AuthorizeCapability::class,
        ]);

        // Marketplace webhooks are called by external servers (eBay) that
        // cannot carry a CSRF token; the handlers authenticate by signature
        // or shared verification token instead.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        // The in-app updater drops the site into maintenance mode while it pulls,
        // migrates, and reloads. Keep its own console — and the bring-back-online
        // action — reachable so an operator is never locked out by a run that was
        // interrupted before it could lift maintenance (it would otherwise 503 too).
        $middleware->preventRequestsDuringMaintenance(except: [
            'admin/system/software/updates',
            'admin/system/software/online',
        ]);

        // Add database connection recovery middleware to web group
        $middleware->web(append: [
            DatabaseConnectionRecovery::class,
            ApplyLocaleContext::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('blb:ai:runs:reap-orphans')->everyFiveMinutes();
        $schedule->command('blb:ai:turns:sweep-stale')->everyFiveMinutes();
        $schedule->command('blb:ai:pricing:refresh')->dailyAt('02:30')->withoutOverlapping();

        // Marketplace order-pull backstop: webhooks deliver sales in near real time,
        // but this incremental, idempotent poll catches anything a webhook missed.
        // Cadence is config-tunable (cron expression); defaults to every 15 minutes.
        $schedule->command('commerce:marketplace:ebay:pull --orders')
            ->cron((string) config('commerce-marketplace.order_poll_cron', '*/15 * * * *'))
            ->withoutOverlapping();

        // Cached eBay category rules (aspects, conditions, compatibility) stay
        // current without a manual refresh button: mapping saves refresh their
        // own category immediately; this nightly sweep covers everything mapped.
        $schedule->command('commerce:marketplace:ebay:metadata-refresh')
            ->cron((string) config('commerce-marketplace.metadata_refresh_cron', '0 3 * * *'))
            ->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions) use (
        $renderAuthenticationException,
        $renderTokenMismatchException,
        $renderUnauthorizedLivewireException,
        $reportBlbException,
        $renderBlbException,
    ) {
        $exceptions->render($renderAuthenticationException);
        $exceptions->render($renderTokenMismatchException);
        $exceptions->render($renderUnauthorizedLivewireException);
        $exceptions->report($reportBlbException);
        $exceptions->render($renderBlbException);
    })->create();
