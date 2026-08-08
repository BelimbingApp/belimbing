<?php

namespace App\Base\Software\Services;

use App\Base\Support\PhpCli;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

final class DeploymentWorkerReloader
{
    private const ADMIN_CONNECT_TIMEOUT_SECONDS = 2;

    private const ADMIN_REQUEST_TIMEOUT_SECONDS = 10;

    private const HEALTH_CONNECT_TIMEOUT_SECONDS = 1;

    private const HEALTH_REQUEST_TIMEOUT_SECONDS = 2;

    private const HEALTH_CHECK_ATTEMPTS = 8;

    private const HEALTH_CHECK_INTERVAL_MICROSECONDS = 250_000;

    public function __construct(
        private readonly DeploymentAdminEndpointResolver $adminEndpoints,
        private readonly DeploymentRunHistory $history,
    ) {}

    /**
     * Graceful, non-elevated worker reload: probe the Caddy admin API for a
     * FrankenPHP worker config, POST FrankenPHP's worker restart endpoint, then
     * queue:restart. The scheduler self-refreshes per run.
     *
     * @return list<string>
     */
    public function reload(bool $clearRuntimeCaches = true): array
    {
        $log = [];

        if ($clearRuntimeCaches) {
            $log[] = $this->clearRuntimeCaches();
        }

        $log[] = $this->warmRuntimeBootstrap();

        $probe = $this->probeAndReloadWebWorkers();
        $webReloaded = $probe['web_reloaded'];
        $adminReachable = $probe['admin_reachable'];
        $reloadMessage = $probe['message'];
        $adminUrl = $probe['admin_url'];

        // No admin endpoint answered. On its own that does NOT mean the runtime is
        // down — a wrong CADDY_SERVER_ADMIN_PORT looks identical from here while
        // workers keep serving old code — so confirm against the application
        // itself. /up is exempt from maintenance mode, so it still answers while an
        // update holds the site down. Silent there too means no worker pool exists
        // to be holding stale classes, and whenever the app is next started it boots
        // the freshly pulled files: there is no mixed-version window, so the caller
        // must not strand the site in maintenance waiting for one to close.
        $runtimeAbsent = ! $webReloaded
            && ! $adminReachable
            && ! $this->applicationResponding();

        if ($runtimeAbsent) {
            // Prefix comes from the classifier so "not a warning, but nothing was
            // reloaded either" cannot drift out of sync with the completion line.
            $reloadMessage = (string) __(':prefix, so none can be serving old code: the FrankenPHP admin API is not listening and the application is not answering health checks. The next start boots the updated code.', [
                'prefix' => DeploymentLogClassifier::NO_RUNTIME_PREFIX,
            ]);
        }

        $log[] = $reloadMessage;

        Artisan::call('queue:restart');
        $log[] = (string) __('Queue restart signaled.');
        $this->history->rememberReload($webReloaded || $runtimeAbsent, $reloadMessage, $adminUrl);

        return $log;
    }

    /**
     * @return array{web_reloaded: bool, admin_reachable: bool, message: string, admin_url: string}
     */
    private function probeAndReloadWebWorkers(): array
    {
        $webReloaded = false;
        $adminReachable = false;
        $reloadMessage = '';
        $adminUrl = '';
        $candidates = $this->adminEndpoints->candidates();

        Log::debug('FrankenPHP worker reload probing admin API candidates.', [
            'candidates' => array_map(
                static fn (array $candidate): string => "{$candidate[0]}:{$candidate[1]}",
                $candidates,
            ),
        ]);

        foreach ($candidates as [$host, $port]) {
            $attempt = $this->attemptReloadAtAdmin($host, $port);
            $adminUrl = $attempt['admin_url'];
            $reloadMessage = $attempt['message'];

            if ($attempt['admin_reachable']) {
                $adminReachable = true;
            }

            if ($attempt['web_reloaded']) {
                $webReloaded = true;

                break;
            }

            if ($attempt['stop']) {
                break;
            }
        }

        return [
            'web_reloaded' => $webReloaded,
            'admin_reachable' => $adminReachable,
            'message' => $reloadMessage,
            'admin_url' => $adminUrl,
        ];
    }

    /**
     * @return array{web_reloaded: bool, admin_reachable: bool, stop: bool, message: string, admin_url: string}
     */
    private function attemptReloadAtAdmin(string $host, int|string $port): array
    {
        $configUrl = "http://{$host}:{$port}/config/apps/frankenphp";
        $restartUrl = "http://{$host}:{$port}/frankenphp/workers/restart";

        $probeFailure = $this->probeAdminConfig($configUrl);

        return $probeFailure ?? $this->restartAndVerifyWorkers($restartUrl);
    }

    /**
     * GET the FrankenPHP admin config. Returns null when a worker pool was found
     * and the caller should proceed to restart it; otherwise the terminal outcome.
     *
     * @return array{web_reloaded: bool, admin_reachable: bool, stop: bool, message: string, admin_url: string}|null
     */
    private function probeAdminConfig(string $configUrl): ?array
    {
        try {
            $config = $this->sendFrankenPhpAdminRequest(
                fn (): Response => $this->frankenPhpAdminHttp()->get($configUrl),
            );

            // An HTTP response of any status means something is listening here.
            if ($this->frankenPhpWorkerConfigPresent($config)) {
                return null;
            }

            Log::debug('FrankenPHP worker reload GET returned no worker config.', [
                'admin_url' => $configUrl,
                'status' => $config->status(),
            ]);

            return [
                'web_reloaded' => false,
                'admin_reachable' => true,
                'stop' => false,
                'message' => (string) __('Warning: web workers were not reloaded because the FrankenPHP admin API at :url did not expose worker config. Check CADDY_SERVER_ADMIN_HOST and CADDY_SERVER_ADMIN_PORT.', ['url' => $configUrl]),
                'admin_url' => $configUrl,
            ];
        } catch (\Throwable $exception) {
            return $this->adminUnreachableOutcome($configUrl, false, $exception);
        }
    }

    /**
     * POST the FrankenPHP worker restart and wait for health to recover. Only
     * called once probeAdminConfig() has confirmed the admin API exposes worker
     * config.
     *
     * @return array{web_reloaded: bool, admin_reachable: bool, stop: bool, message: string, admin_url: string}
     */
    private function restartAndVerifyWorkers(string $restartUrl): array
    {
        try {
            $restart = $this->sendFrankenPhpAdminRequest(
                fn (): Response => $this->frankenPhpAdminHttp()->post($restartUrl),
            );

            if (! $restart->successful()) {
                Log::debug('FrankenPHP worker restart failed.', [
                    'admin_url' => $restartUrl,
                    'status' => $restart->status(),
                ]);

                return [
                    'web_reloaded' => false,
                    'admin_reachable' => true,
                    'stop' => false,
                    'message' => (string) __('Warning: web workers were not reloaded; the FrankenPHP admin API returned HTTP :status. Running workers may keep old code until they restart.', ['status' => $restart->status()]),
                    'admin_url' => $restartUrl,
                ];
            }

            return $this->healthCheckOutcome($restartUrl);
        } catch (\Throwable $exception) {
            return $this->adminUnreachableOutcome($restartUrl, true, $exception);
        }
    }

    /**
     * @return array{web_reloaded: bool, admin_reachable: bool, stop: bool, message: string, admin_url: string}
     */
    private function healthCheckOutcome(string $restartUrl): array
    {
        $healthFailure = $this->waitForApplicationHealth();

        if ($healthFailure === null) {
            Log::debug('FrankenPHP worker reload succeeded.', ['admin_url' => $restartUrl]);

            return [
                'web_reloaded' => true,
                'admin_reachable' => true,
                'stop' => true,
                'message' => (string) __('Web workers reloaded.'),
                'admin_url' => $restartUrl,
            ];
        }

        Log::debug('FrankenPHP worker reload health check failed.', [
            'admin_url' => $restartUrl,
            'message' => $healthFailure,
        ]);

        return [
            'web_reloaded' => false,
            'admin_reachable' => true,
            'stop' => true,
            'message' => (string) __('Warning: web workers restart was accepted, but the application health check did not recover: :message', ['message' => $healthFailure]),
            'admin_url' => $restartUrl,
        ];
    }

    /**
     * @return array{web_reloaded: bool, admin_reachable: bool, stop: bool, message: string, admin_url: string}
     */
    private function adminUnreachableOutcome(string $adminUrl, bool $adminReachable, \Throwable $exception): array
    {
        Log::debug('FrankenPHP worker reload request failed.', [
            'admin_url' => $adminUrl,
            'message' => $exception->getMessage(),
        ]);

        return [
            'web_reloaded' => false,
            'admin_reachable' => $adminReachable,
            'stop' => false,
            'message' => (string) __('Warning: web workers were not reloaded because the FrankenPHP admin API at :url could not be reached: :message', ['url' => $adminUrl, 'message' => $exception->getMessage()]),
            'admin_url' => $adminUrl,
        ];
    }

    /**
     * One fast pass over the health endpoints: is anything serving this
     * application right now? Unlike waitForApplicationHealth() this does not
     * retry — it is asking whether a worker pool exists at all, not waiting for
     * one to come back, so a single silent pass is the answer.
     */
    private function applicationResponding(): bool
    {
        foreach ($this->adminEndpoints->healthCheckUrls() as $url) {
            try {
                if (Http::connectTimeout(self::HEALTH_CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::HEALTH_REQUEST_TIMEOUT_SECONDS)
                    ->get($url)
                    ->successful()) {
                    return true;
                }
            } catch (\Throwable) {
                // Unreachable candidate; keep probing the remaining URLs.
            }
        }

        return false;
    }

    private function frankenPhpAdminHttp(): PendingRequest
    {
        return Http::connectTimeout(self::ADMIN_CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::ADMIN_REQUEST_TIMEOUT_SECONDS);
    }

    private function frankenPhpWorkerConfigPresent(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $config = $response->json();

        return is_array($config) && array_key_exists('workers', $config);
    }

    /**
     * @param  callable(): Response  $request
     */
    private function sendFrankenPhpAdminRequest(callable $request): Response
    {
        try {
            return $request();
        } catch (\Throwable $exception) {
            if (! $this->isHttpTimeout($exception)) {
                throw $exception;
            }

            return $request();
        }
    }

    private function isHttpTimeout(\Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'cURL error 28')
            || str_contains(strtolower($message), 'timed out');
    }

    private function clearRuntimeCaches(): string
    {
        Artisan::call('optimize:clear');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return (string) __('Runtime caches cleared.');
    }

    /**
     * FrankenPHP boots many workers concurrently. When the provider list changes
     * (for example, enabling/disabling a domain), letting every worker compile
     * bootstrap/cache/services.php at once is unreliable on Windows. Warm it once
     * in a normal CLI process before asking FrankenPHP to respawn the pool.
     */
    private function warmRuntimeBootstrap(): string
    {
        $warm = Process::path(base_path())
            ->timeout(60)
            ->run(PhpCli::current()->artisan(['about', '--only=environment']));

        if ($warm->successful()) {
            return (string) __('Runtime bootstrap warmed.');
        }

        $output = trim($warm->output()."\n".$warm->errorOutput());

        return (string) __('Warning: runtime bootstrap warmup failed before worker reload: :message', [
            'message' => $output !== '' ? $output : __('process exited with code :code', ['code' => $warm->exitCode()]),
        ]);
    }

    private function waitForApplicationHealth(): ?string
    {
        $urls = $this->adminEndpoints->healthCheckUrls();
        $lastFailure = null;

        for ($attempt = 1; $attempt <= self::HEALTH_CHECK_ATTEMPTS; $attempt++) {
            foreach ($urls as $url) {
                try {
                    $response = Http::connectTimeout(self::HEALTH_CONNECT_TIMEOUT_SECONDS)
                        ->timeout(self::HEALTH_REQUEST_TIMEOUT_SECONDS)
                        ->get($url);

                    if ($response->successful()) {
                        return null;
                    }

                    $lastFailure = (string) __(':url returned HTTP :status', [
                        'url' => $url,
                        'status' => $response->status(),
                    ]);
                } catch (\Throwable $exception) {
                    $lastFailure = (string) __(':url failed: :message', [
                        'url' => $url,
                        'message' => $exception->getMessage(),
                    ]);
                }
            }

            if ($attempt < self::HEALTH_CHECK_ATTEMPTS) {
                usleep(self::HEALTH_CHECK_INTERVAL_MICROSECONDS);
            }
        }

        return $lastFailure ?? (string) __('health endpoint did not respond');
    }
}
